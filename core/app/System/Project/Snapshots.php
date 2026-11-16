<?php

namespace App\System\Project;

use App\Exceptions\RollbackIncompleteException;
use App\Models\Snapshot;
use App\Support\JobDeadline;
use App\System\Project as UserProject;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\DatabaseRestore;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Snapshot\SnapshotStore;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A project's database snapshots: taken while the app keeps running, restored with it stopped.
 * Every call runs inside the project's deploy lock, which the caller holds.
 */
final class Snapshots
{
    /** Left free beyond the databases' own size, as the store shares its volume with core's database. */
    private const int RESERVE_BYTES = 1024 * 1024 * 1024;

    public function __construct(
        private UserProject $project,
        private SnapshotStore $store,
        private ProjectDatabases $databases,
        private AppStack $stack,
    ) {}

    public static function of(UserProject $project, ?JobDeadline $deadline = null): self
    {
        return new self(
            $project,
            new SnapshotStore,
            new ProjectDatabases($project, $deadline ?? JobDeadline::startingNow()),
            new AppStack($project),
        );
    }

    /** Null when the project owns no database. A failed dump leaves a failed row and no files. */
    public function take(string $kind, ?string $deployId = null): ?Snapshot
    {
        $names = $this->databases->names();
        if ($names === []) {
            return null;
        }

        $this->assertRoomFor($names);
        $snapshot = Snapshot::begin($this->project->model(), $kind, $deployId);
        try {
            $snapshot->complete($this->dumpAll($snapshot, $names));
        } catch (Throwable $e) {
            $snapshot->fail($e->getMessage());
            $this->discardFiles($snapshot);

            throw $e;
        }

        return $snapshot;
    }

    /**
     * Every database of the snapshot, or the $only named, each behind a dump of its current state;
     * a failure puts back the ones already restored. Nothing stops before the files are checked.
     *
     * @param  list<string>  $only
     */
    public function restore(Snapshot $snapshot, array $only = []): void
    {
        $entries = $this->restorableEntries($snapshot, $only);
        $snapshot->saveRestoreStatus(Snapshot::STATUS_RUNNING);

        try {
            if ($this->project->runsInDind()) {
                $this->whileStopped(fn () => $this->restoreEntries($snapshot, $entries));
            } else {
                $this->restoreEntries($snapshot, $entries);
            }
        } catch (Throwable $e) {
            $snapshot->saveRestoreStatus(Snapshot::STATUS_FAILED, $e->getMessage());

            throw $e;
        }

        $snapshot->saveRestoreStatus(Snapshot::STATUS_COMPLETED);
    }

    public function delete(Snapshot $snapshot): void
    {
        $this->store->remove($snapshot);
        $snapshot->delete();
    }

    /** Keeps the newest $keep snapshots of $kind; one still being taken is never counted or removed. */
    public function prune(string $kind, int $keep): int
    {
        $newestFirst = Snapshot::query()
            ->where('user_id', $this->project->model()->id)
            ->where('kind', $kind)
            ->where('status', '!=', Snapshot::STATUS_RUNNING)
            ->orderByDesc('id')
            ->get()
            ->all();
        $stale = array_slice($newestFirst, max(0, $keep));

        foreach ($stale as $snapshot) {
            $this->delete($snapshot);
        }

        return count($stale);
    }

    /** Files a failed snapshot could not remove stay until it is pruned or deleted. */
    private function discardFiles(Snapshot $snapshot): void
    {
        try {
            $this->store->remove($snapshot);
        } catch (Throwable $e) {
            Log::warning('A failed snapshot left files behind', [
                'snapshot_id' => $snapshot->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function assertRoomFor(array $names): void
    {
        $needed = self::RESERVE_BYTES;
        foreach ($names as $name) {
            $needed += $this->databases->size($name);
        }

        $free = $this->store->freeBytes();
        if ($free < $needed) {
            throw new \RuntimeException(sprintf(
                'Not enough free space for a database snapshot: need %d bytes, have %d bytes',
                $needed,
                $free,
            ));
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, file: string, size_bytes: int, sha256: string}>
     */
    private function dumpAll(Snapshot $snapshot, array $names): array
    {
        $directory = $this->store->create($snapshot);
        $entries = [];
        foreach ($names as $name) {
            $file = 'database-'.$name.'.sql.gz';
            $this->databases->dump($name, $directory.$file);
            $entries[] = [
                'name' => $name,
                'file' => $file,
                'size_bytes' => (int) filesize($directory.$file),
                'sha256' => (string) hash_file('sha256', $directory.$file),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $only
     * @return list<array{name: string, file: string, size_bytes: int, sha256: string}>
     */
    private function restorableEntries(Snapshot $snapshot, array $only): array
    {
        if (! $snapshot->isCompleted()) {
            throw new \RuntimeException('Snapshot is not complete');
        }

        $entries = $snapshot->entries();
        $unknown = array_diff($only, array_column($entries, 'name'));
        if ($unknown !== []) {
            throw new \RuntimeException('The snapshot holds no database named '.implode(', ', $unknown));
        }

        $selected = $only === []
            ? $entries
            : array_values(array_filter($entries, fn (array $entry): bool => in_array($entry['name'], $only, true)));
        foreach ($selected as $entry) {
            $this->assertIntact($snapshot, $entry);
        }

        return $selected;
    }

    /**
     * @param  array{name: string, file: string, size_bytes: int, sha256: string}  $entry
     */
    private function assertIntact(Snapshot $snapshot, array $entry): void
    {
        $path = $this->store->directory($snapshot).$entry['file'];
        if (! is_file($path) || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            throw new \RuntimeException("The snapshot's dump of {$entry['name']} is missing or changed");
        }
    }

    /** A stack whose databases could not be put back stays stopped. */
    private function whileStopped(callable $work): void
    {
        $this->stack->stop();
        try {
            $work();
        } catch (Throwable $e) {
            if (! $e instanceof RollbackIncompleteException) {
                $this->tryStart();
            }

            throw $e;
        }
        $this->stack->start();
    }

    private function tryStart(): void
    {
        try {
            $this->stack->start();
        } catch (Throwable $e) {
            Log::warning('Snapshot restore could not start the stack again', [
                'username' => $this->project->username(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The pre-restore dumps go once nothing needs them; after an incomplete rollback they stay
     * in the snapshot's directory, as the only copy of what the databases held before.
     *
     * @param  list<array{name: string, file: string, size_bytes: int, sha256: string}>  $entries
     */
    private function restoreEntries(Snapshot $snapshot, array $entries): void
    {
        $directory = $this->store->directory($snapshot);
        $restore = new DatabaseRestore($this->databases, $directory);
        try {
            foreach ($entries as $entry) {
                $this->databases->ensureRow($entry['name']);
                $restore->restore($entry['name'], $directory.$entry['file']);
            }
        } catch (Throwable $e) {
            $restore->rollBack();
            if ($restore->undoFailed()) {
                throw new RollbackIncompleteException($e, ["putting the databases back from the pre-restore dumps kept in {$directory}"]);
            }
            $this->store->removePreRestoreDumps($snapshot);

            throw $e;
        }

        $this->store->removePreRestoreDumps($snapshot);
    }
}
