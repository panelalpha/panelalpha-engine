<?php

namespace Tests\Unit\Backup;

use App\Jobs\DeleteBackup;
use App\Jobs\DeleteBackupContainer;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The parts of the two delete jobs that decide what happens without touching
 * storage: nothing left to delete, an empty container, and how a failure is
 * written onto the record.
 */
class BackupDeletionJobsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Container credentials are an encrypted cast.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    private function container(string $name = 'disk'): BackupContainer
    {
        return BackupContainer::create(['name' => $name, 'driver' => 'local', 'location' => "/backups/{$name}", 'credentials' => null]);
    }

    private function backup(BackupContainer $container, array $asyncStatus = []): BackupRecord
    {
        $user = $this->makeUser('alice');

        return BackupRecord::create([
            'user_id' => $user->id,
            'username' => 'alice',
            'container_id' => $container->id,
            'async_status' => $asyncStatus,
        ]);
    }

    public function test_both_jobs_run_once_for_up_to_two_hours(): void
    {
        foreach ([new DeleteBackup(1), new DeleteBackupContainer(1)] as $job) {
            $this->assertSame(1, $job->tries);
            $this->assertSame(7200, $job->timeout);
        }
    }

    public function test_a_container_already_gone_is_nothing_to_do(): void
    {
        (new DeleteBackupContainer(404))->handle();

        $this->assertSame(0, BackupContainer::query()->count());
    }

    public function test_an_empty_container_is_deleted(): void
    {
        $container = $this->container();
        $this->container('kept');

        (new DeleteBackupContainer($container->id))->handle();

        $this->assertNull(BackupContainer::query()->find($container->id));
        $this->assertSame(1, BackupContainer::query()->count(), 'only the one asked for');
    }

    public function test_a_backup_already_gone_is_nothing_to_do(): void
    {
        (new DeleteBackup(404))->handle();

        $this->assertSame(0, BackupRecord::query()->count());
    }

    public function test_a_failed_delete_is_written_onto_the_record(): void
    {
        $record = $this->backup($this->container(), ['delete' => 'pending']);

        (new DeleteBackup($record->id))->failed(new \RuntimeException('storage unreachable'));

        $record->refresh();
        $this->assertSame('failed', $record->deleteStatus());
        $this->assertSame('storage unreachable', $record->error);
    }

    public function test_a_finished_delete_is_not_overwritten_by_a_late_failure(): void
    {
        $record = $this->backup($this->container(), ['delete' => 'completed']);

        (new DeleteBackup($record->id))->failed(new \RuntimeException('late'));

        $record->refresh();
        $this->assertSame('completed', $record->deleteStatus());
        $this->assertNull($record->error);
    }

    public function test_a_failure_with_no_exception_still_says_why(): void
    {
        $record = $this->backup($this->container());

        (new DeleteBackup($record->id))->failed(null);

        $record->refresh();
        $this->assertSame('failed', $record->deleteStatus());
        $this->assertSame('Delete job failed', $record->error);
    }
}
