<?php

namespace Tests\Integration\System\Project\Snapshot;

use App\Models\Snapshot;
use App\Models\User;
use App\Support\JobDeadline;
use App\System;
use App\System\Project;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Snapshot\SnapshotStore;
use App\System\Project\Snapshots;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Snapshots and their store when a step refuses: files that cannot be removed, a stack that will not start
 * again, a snapshot not finished yet, and a store directory that cannot be made.
 */
class SnapshotsRefusedStepsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-snapshots-refused-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_default_snapshots_prune_a_snapshot_whose_files_are_already_gone(): void
    {
        $user = $this->user();
        $old = Snapshot::begin($user, Snapshot::KIND_MANUAL);
        $old->complete([]);

        $pruned = Snapshots::of(new Project(new System(), $user))->prune(Snapshot::KIND_MANUAL, 0);

        $this->assertSame(1, $pruned);
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_a_failed_dump_whose_files_cannot_be_removed_is_still_reported_as_the_dump(): void
    {
        Log::spy();
        $snapshots = $this->snapshots($this->user(), new class ($this->root) extends SnapshotStore {
            public function remove(Snapshot $snapshot): void
            {
                throw new \RuntimeException('directory busy');
            }

            public function freeBytes(): int
            {
                return PHP_INT_MAX;
            }
        });

        try {
            $snapshots->take(Snapshot::KIND_MANUAL);
            $this->fail('A failed dump was taken.');
        } catch (\RuntimeException $e) {
            $this->assertSame('dump failed', $e->getMessage());
        }

        $this->assertSame(Snapshot::STATUS_FAILED, Snapshot::query()->sole()->status);
        Log::shouldHaveReceived('warning')->with('A failed snapshot left files behind', \Mockery::on(
            fn (array $context): bool => $context['message'] === 'directory busy',
        ));
    }

    public function test_a_snapshot_still_being_taken_is_not_restored(): void
    {
        $snapshot = Snapshot::begin($this->user(), Snapshot::KIND_MANUAL);

        try {
            $this->snapshots($this->user(), new SnapshotStore($this->root))->restore($snapshot);
            $this->fail('A running snapshot was restored.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Snapshot is not complete', $e->getMessage());
        }
        $this->assertNull($snapshot->fresh()->restore_status);
    }

    public function test_a_failed_restore_whose_stack_will_not_start_again_reports_the_restore(): void
    {
        Log::spy();
        $user = $this->user();
        $store = new SnapshotStore($this->root);
        $snapshot = Snapshot::begin($user, Snapshot::KIND_MANUAL);
        $directory = $store->create($snapshot);
        file_put_contents($directory . 'database-alice_app.sql.gz', 'dump');
        $snapshot->complete([[
            'name' => 'alice_app',
            'file' => 'database-alice_app.sql.gz',
            'size_bytes' => 4,
            'sha256' => hash('sha256', 'dump'),
        ]]);

        try {
            $this->snapshots($user, $store, restoreFails: true, startFails: true)->restore($snapshot);
            $this->fail('A failed restore was reported as done.');
        } catch (\RuntimeException $e) {
            $this->assertSame('restore failed', $e->getMessage());
        }

        $this->assertSame(Snapshot::STATUS_FAILED, $snapshot->fresh()->restore_status);
        Log::shouldHaveReceived('warning')->with('Snapshot restore could not start the stack again', [
            'username' => 'alice',
            'message' => 'the stack will not start',
        ]);
    }

    public function test_a_store_whose_root_is_a_file_cannot_make_a_snapshot_directory(): void
    {
        mkdir($this->root, 0700, true);
        file_put_contents($this->root . '/store', '');
        $snapshot = Snapshot::begin($this->user(), Snapshot::KIND_MANUAL);

        $this->expectExceptionObject(new \RuntimeException(
            'Failed to create the snapshot directory ' . $this->root . '/store/alice/' . $snapshot->id . '/'
        ));
        (new SnapshotStore($this->root . '/store'))->create($snapshot);
    }

    public function test_a_snapshot_directory_holding_a_directory_cannot_be_removed(): void
    {
        $store = new SnapshotStore($this->root);
        $snapshot = Snapshot::begin($this->user(), Snapshot::KIND_MANUAL);
        $directory = $store->create($snapshot);
        mkdir($directory . 'nested');
        file_put_contents($directory . 'database-alice_app.sql.gz', 'dump');

        try {
            $store->remove($snapshot);
            $this->fail('A directory still holding one was removed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to remove the snapshot directory ' . $directory, $e->getMessage());
        }
        $this->assertFileDoesNotExist($directory . 'database-alice_app.sql.gz');
        $this->assertDirectoryExists($directory . 'nested');
    }

    public function test_free_space_is_read_from_the_nearest_directory_that_exists(): void
    {
        $free = (new SnapshotStore($this->root . '/not/made/yet'))->freeBytes();

        $this->assertGreaterThan(0, $free);
        $this->assertDirectoryDoesNotExist($this->root);
    }

    private function user(): User
    {
        return User::query()->where('username', 'alice')->first() ?? $this->makeUser('alice', ['template' => 'dind']);
    }

    private function snapshots(User $user, SnapshotStore $store, bool $restoreFails = false, bool $startFails = false): Snapshots
    {
        $project = new Project(new System(), $user);

        return new Snapshots(
            $project,
            $store,
            new class ($project, JobDeadline::startingNow(), $restoreFails) extends ProjectDatabases {
                public function __construct(Project $project, JobDeadline $deadline, private bool $restoreFails)
                {
                    parent::__construct($project, $deadline);
                }

                public function names(): array
                {
                    return ['alice_app'];
                }

                public function size(string $name): int
                {
                    return 0;
                }

                public function dump(string $name, string $dest): void
                {
                    if (! str_contains($dest, 'pre-restore-')) {
                        throw new \RuntimeException('dump failed');
                    }
                    file_put_contents($dest, 'before');
                }

                public function restore(string $name, string $sqlGzPath, ?int $timeout = null): void
                {
                    if ($this->restoreFails && ! str_contains($sqlGzPath, 'pre-restore-')) {
                        throw new \RuntimeException('restore failed');
                    }
                }

                public function hasRow(string $name): bool
                {
                    return true;
                }
            },
            new class ($project, $startFails) extends AppStack {
                public function __construct(Project $project, private bool $startFails)
                {
                    parent::__construct($project);
                }

                public function stop(): void
                {
                }

                public function start(): void
                {
                    if ($this->startFails) {
                        throw new \RuntimeException('the stack will not start');
                    }
                }
            },
        );
    }
}
