<?php

namespace Tests\Integration\System\Project\Snapshot;

use App\Exceptions\RollbackIncompleteException;
use App\Models\Snapshot;
use App\Models\User;
use App\Support\JobDeadline;
use App\System;
use App\System\Project;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Snapshot\SnapshotStore;
use App\System\Project\Snapshots;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class SnapshotsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private \ArrayObject $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-snapshots-' . bin2hex(random_bytes(4));
        $this->journal = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_snapshot_dumps_every_database_while_the_app_keeps_running(): void
    {
        $snapshot = $this->snapshots($this->user())->take(Snapshot::KIND_PRE_DEPLOY, 'deploy-7');

        $this->assertSame(Snapshot::STATUS_COMPLETED, $snapshot->status);
        $this->assertSame('deploy-7', $snapshot->deploy_id);
        $this->assertSame(['alice_app', 'alice_wp'], array_column($snapshot->entries(), 'name'));
        $directory = $this->store()->directory($snapshot);
        foreach ($snapshot->entries() as $entry) {
            $this->assertSame('database-' . $entry['name'] . '.sql.gz', $entry['file']);
            $this->assertSame(hash_file('sha256', $directory . $entry['file']), $entry['sha256']);
        }
        $this->assertSame(array_sum(array_column($snapshot->entries(), 'size_bytes')), $snapshot->size_bytes);
        $this->assertSame(['dump alice_app database-alice_app.sql.gz', 'dump alice_wp database-alice_wp.sql.gz'], $this->journal->getArrayCopy());
    }

    public function test_a_project_without_databases_takes_no_snapshot(): void
    {
        $this->assertNull($this->snapshots($this->user(), databases: [])->take(Snapshot::KIND_MANUAL));
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_too_little_free_space_refuses_before_anything_is_written(): void
    {
        try {
            $this->snapshots($this->user(), freeBytes: 1000)->take(Snapshot::KIND_MANUAL);
            $this->fail('the snapshot should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Not enough free space for a database snapshot', $e->getMessage());
        }

        $this->assertSame(0, Snapshot::query()->count());
        $this->assertDirectoryDoesNotExist($this->root);
        $this->assertSame([], $this->journal->getArrayCopy());
    }

    public function test_a_failed_dump_leaves_a_failed_row_and_no_files(): void
    {
        try {
            $this->snapshots($this->user(), failing: ['database-alice_wp.sql.gz'])->take(Snapshot::KIND_MANUAL);
            $this->fail('the dump should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('dump of alice_wp failed', $e->getMessage());
        }

        $snapshot = Snapshot::query()->sole();
        $this->assertSame(Snapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame('dump of alice_wp failed', $snapshot->error);
        $this->assertSame([], $snapshot->entries());
        $this->assertDirectoryDoesNotExist($this->store()->directory($snapshot));
    }

    public function test_a_restore_puts_every_database_back_with_the_stack_stopped(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        $this->snapshots($user)->restore($snapshot);

        $this->assertSame([
            'stop',
            'dump alice_app pre-restore-alice_app.sql.gz',
            'restore alice_app database-alice_app.sql.gz',
            'dump alice_wp pre-restore-alice_wp.sql.gz',
            'restore alice_wp database-alice_wp.sql.gz',
            'start',
        ], $this->journal->getArrayCopy());
        $this->assertSame(Snapshot::STATUS_COMPLETED, $snapshot->fresh()->restore_status);
        $this->assertSame([], glob($this->store()->directory($snapshot) . 'pre-restore-*'));
    }

    public function test_a_restore_can_put_back_only_the_databases_it_names(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        $this->snapshots($user)->restore($snapshot, ['alice_wp']);

        $this->assertSame([
            'stop',
            'dump alice_wp pre-restore-alice_wp.sql.gz',
            'restore alice_wp database-alice_wp.sql.gz',
            'start',
        ], $this->journal->getArrayCopy());
    }

    public function test_a_name_the_snapshot_does_not_hold_is_refused_before_anything_stops(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        $this->expectExceptionMessage('The snapshot holds no database named alice_shop');
        try {
            $this->snapshots($user)->restore($snapshot, ['alice_shop']);
        } finally {
            $this->assertSame([], $this->journal->getArrayCopy());
            $this->assertNull($snapshot->fresh()->restore_status);
        }
    }

    public function test_a_changed_dump_is_refused_before_anything_stops(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        file_put_contents($this->store()->directory($snapshot) . 'database-alice_wp.sql.gz', 'tampered');
        $this->journal->exchangeArray([]);

        $this->expectExceptionMessage("The snapshot's dump of alice_wp is missing or changed");
        try {
            $this->snapshots($user)->restore($snapshot);
        } finally {
            $this->assertSame([], $this->journal->getArrayCopy());
        }
    }

    public function test_a_failed_restore_puts_back_what_it_restored_and_starts_the_stack(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        try {
            $this->snapshots($user, failing: ['restore:database-alice_wp.sql.gz'])->restore($snapshot);
            $this->fail('the restore should have failed');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(RollbackIncompleteException::class, $e);
        }

        $this->assertSame([
            'stop',
            'dump alice_app pre-restore-alice_app.sql.gz',
            'restore alice_app database-alice_app.sql.gz',
            'dump alice_wp pre-restore-alice_wp.sql.gz',
            'restore alice_wp database-alice_wp.sql.gz',
            'restore alice_wp pre-restore-alice_wp.sql.gz',
            'restore alice_app pre-restore-alice_app.sql.gz',
            'start',
        ], $this->journal->getArrayCopy());
        $this->assertSame(Snapshot::STATUS_FAILED, $snapshot->fresh()->restore_status);
        $this->assertSame([], glob($this->store()->directory($snapshot) . 'pre-restore-*'));
    }

    public function test_an_incomplete_rollback_keeps_the_stack_stopped_and_the_pre_restore_dumps(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        try {
            $this->snapshots($user, failing: ['restore:database-alice_wp.sql.gz', 'restore:pre-restore-alice_app.sql.gz'])->restore($snapshot);
            $this->fail('the restore should have failed');
        } catch (RollbackIncompleteException $e) {
            $this->assertSame('restore of database-alice_wp.sql.gz failed', $e->getMessage());
        }

        $this->assertNotContains('start', $this->journal->getArrayCopy());
        $this->assertFileExists($this->store()->directory($snapshot) . 'pre-restore-alice_app.sql.gz');
    }

    public function test_a_classic_restore_never_touches_a_stack(): void
    {
        $user = $this->user('default');
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);
        $this->journal->exchangeArray([]);

        $this->snapshots($user)->restore($snapshot);

        $this->assertNotContains('stop', $this->journal->getArrayCopy());
        $this->assertNotContains('start', $this->journal->getArrayCopy());
    }

    public function test_pruning_keeps_the_newest_of_one_kind_only(): void
    {
        $user = $this->user();
        $snapshots = $this->snapshots($user);
        $oldest = $snapshots->take(Snapshot::KIND_PRE_DEPLOY);
        $manual = $snapshots->take(Snapshot::KIND_MANUAL);
        $kept = [$snapshots->take(Snapshot::KIND_PRE_DEPLOY), $snapshots->take(Snapshot::KIND_PRE_DEPLOY)];
        $running = Snapshot::begin($user, Snapshot::KIND_PRE_DEPLOY);

        $this->assertSame(1, $snapshots->prune(Snapshot::KIND_PRE_DEPLOY, 2));

        $this->assertNull($oldest->fresh());
        $this->assertDirectoryDoesNotExist($this->store()->directory($oldest));
        $this->assertNotNull($manual->fresh());
        $this->assertNotNull($running->fresh());
        foreach ($kept as $snapshot) {
            $this->assertDirectoryExists($this->store()->directory($snapshot));
        }
    }

    public function test_deleting_a_snapshot_removes_its_files_and_its_row(): void
    {
        $user = $this->user();
        $snapshot = $this->snapshots($user)->take(Snapshot::KIND_MANUAL);

        $this->snapshots($user)->delete($snapshot);

        $this->assertNull($snapshot->fresh());
        $this->assertDirectoryDoesNotExist($this->store()->directory($snapshot));
    }

    public function test_an_owner_that_is_not_one_path_segment_is_refused(): void
    {
        $snapshot = new Snapshot();
        $snapshot->id = 1;
        $snapshot->username = '../alice';

        $this->expectExceptionMessage("Invalid snapshot owner '../alice'");
        $this->store()->directory($snapshot);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::begin($this->user(), 'weekly');
    }

    private function user(string $template = 'dind'): User
    {
        return User::query()->where('username', 'alice')->first() ?? $this->makeUser('alice', ['template' => $template]);
    }

    /**
     * @param list<string> $databases
     * @param list<string> $failing dump file names, or `restore:` + the file a restore reads
     */
    private function snapshots(User $user, array $databases = ['alice_app', 'alice_wp'], int $freeBytes = PHP_INT_MAX, array $failing = []): Snapshots
    {
        $project = new Project(new System(), $user);

        return new Snapshots(
            $project,
            $this->store($freeBytes),
            $this->databases($project, $databases, $failing),
            $this->stack($project),
        );
    }

    private function store(int $freeBytes = PHP_INT_MAX): SnapshotStore
    {
        return new class ($this->root, $freeBytes) extends SnapshotStore {
            public function __construct(string $root, private int $free)
            {
                parent::__construct($root);
            }

            public function freeBytes(): int
            {
                return $this->free;
            }
        };
    }

    /**
     * @param list<string> $names
     * @param list<string> $failing
     */
    private function databases(Project $project, array $names, array $failing): ProjectDatabases
    {
        return new class ($project, JobDeadline::startingNow(), $this->journal, $names, $failing) extends ProjectDatabases {
            public function __construct(Project $project, JobDeadline $deadline, private \ArrayObject $journal, private array $names, private array $failing)
            {
                parent::__construct($project, $deadline);
            }

            public function names(): array
            {
                return $this->names;
            }

            public function size(string $name): int
            {
                return 1024;
            }

            public function dump(string $name, string $dest): void
            {
                $this->journal[] = 'dump ' . $name . ' ' . basename($dest);
                if (in_array(basename($dest), $this->failing, true)) {
                    throw new \RuntimeException("dump of {$name} failed");
                }
                file_put_contents($dest, "-- {$name} at " . microtime(true));
            }

            public function restore(string $name, string $sqlGzPath, ?int $timeout = null): void
            {
                $this->journal[] = 'restore ' . $name . ' ' . basename($sqlGzPath);
                if (in_array('restore:' . basename($sqlGzPath), $this->failing, true)) {
                    throw new \RuntimeException('restore of ' . basename($sqlGzPath) . ' failed');
                }
            }

            public function hasRow(string $name): bool
            {
                return true;
            }
        };
    }

    private function stack(Project $project): AppStack
    {
        return new class ($project, $this->journal) extends AppStack {
            public function __construct(Project $project, private \ArrayObject $journal)
            {
                parent::__construct($project);
            }

            public function stop(): void
            {
                $this->journal[] = 'stop';
            }

            public function start(): void
            {
                $this->journal[] = 'start';
            }
        };
    }
}
