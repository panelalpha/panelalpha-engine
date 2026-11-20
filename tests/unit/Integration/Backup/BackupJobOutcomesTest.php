<?php

namespace Tests\Integration\Backup;

use App\Jobs\CreateBackup;
use App\Jobs\DeleteBackup;
use App\Jobs\DeleteBackupContainer;
use App\Jobs\RestoreBackup;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\Task;
use App\Models\User;
use App\System\Project\Backup\BackupTransfer;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * How the backup, restore and delete jobs settle their record and their task
 * when the work is refused, fails or finds nothing left to do.
 */
class BackupJobOutcomesTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-backup-jobs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $this->username = 'bkjob' . bin2hex(random_bytes(3));
        $this->refuseEveryRootAction();
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        DeployLogger::deleteUserLogs($this->username);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_backup_whose_owner_is_gone_fails_its_record_and_task(): void
    {
        $record = $this->orphan($this->backup());
        $task = $this->task(CreateBackup::class);

        $this->runJob((new CreateBackup($record->id))->attachTask($task), 'Backup user not found');

        $record->refresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('Backup user not found', $record->error);
        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_backup_refused_by_the_deploy_lock_fails_its_record_with_the_holder(): void
    {
        $record = $this->backup();
        $task = $this->task(CreateBackup::class);
        $lock = DeployLock::acquireFor($this->username, DeployLock::HOLDER_RESTORE);

        try {
            $this->runJob((new CreateBackup($record->id))->attachTask($task), 'A restore is already running for this user.');
        } finally {
            $lock->release();
        }

        $record->refresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('A restore is already running for this user.', $record->error);
        $this->assertSame('A restore is already running for this user.', $task->refresh()->details['error']);
    }

    public function test_a_backup_the_project_failed_raises_the_recorded_error(): void
    {
        $record = $this->backup();
        $task = $this->task(CreateBackup::class);

        $message = $this->runJob((new CreateBackup($record->id))->attachTask($task));

        $record->refresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertNotNull($record->error);
        $this->assertSame($record->error, $message);
        $this->assertSame($record->error, $task->refresh()->details['error']);
    }

    public function test_a_failed_backup_job_settles_an_unfinished_record_and_task(): void
    {
        $record = $this->backup();
        $task = $this->task(CreateBackup::class);

        (new CreateBackup($record->id))->attachTask($task)->failed(null);

        $record->refresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('Backup job failed', $record->error);
        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Backup job failed', $task->details['error']);
    }

    public function test_a_late_backup_failure_leaves_a_completed_record_alone(): void
    {
        $record = $this->backup(['backup' => 'completed']);

        (new CreateBackup($record->id))->failed(new \RuntimeException('worker died'));

        $record->refresh();
        $this->assertSame('completed', $record->backupStatus());
        $this->assertNull($record->error);
    }

    public function test_a_restore_of_a_vanished_backup_fails_its_task(): void
    {
        $task = $this->task(RestoreBackup::class);

        $this->runJob((new RestoreBackup(999))->attachTask($task), 'The backup no longer exists.');

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_restore_whose_owner_is_gone_fails_its_record(): void
    {
        $record = $this->orphan($this->backup(['backup' => 'completed']));
        $task = $this->task(RestoreBackup::class);

        $this->runJob((new RestoreBackup($record->id))->attachTask($task), 'Backup user not found');

        $record->refresh();
        $this->assertSame('failed', $record->restoreStatus());
        $this->assertSame('Backup user not found', $record->error);
    }

    public function test_a_restore_of_an_unfinished_backup_is_refused(): void
    {
        $record = $this->backup(['backup' => 'running']);
        $task = $this->task(RestoreBackup::class);

        $this->runJob((new RestoreBackup($record->id))->attachTask($task), 'Backup is not complete');

        $this->assertSame('Backup is not complete', $task->refresh()->details['error']);
    }

    public function test_a_restore_refused_by_the_deploy_lock_fails_its_record_with_the_holder(): void
    {
        $record = $this->completedBackup();
        $task = $this->task(RestoreBackup::class);
        $lock = DeployLock::acquireFor($this->username, DeployLock::HOLDER_BACKUP);

        try {
            $this->runJob((new RestoreBackup($record->id))->attachTask($task), 'A backup is already running for this user.');
        } finally {
            $lock->release();
        }

        $record->refresh();
        $this->assertSame('failed', $record->restoreStatus());
        $this->assertSame('A backup is already running for this user.', $record->error);
    }

    public function test_a_restore_the_project_failed_raises_the_recorded_error(): void
    {
        $record = $this->completedBackup();
        $task = $this->task(RestoreBackup::class);

        $job = new RestoreBackup($record->id, only: ['databases' => ['missing_db']]);
        $this->runJob($job->attachTask($task), 'No backup items match the restore filter');

        $record->refresh();
        $this->assertSame('failed', $record->restoreStatus());
        $this->assertSame('No backup items match the restore filter', $record->error);
        $this->assertSame(['databases' => ['missing_db']], $record->restore_details['only']);
    }

    public function test_a_failed_restore_job_settles_an_unfinished_record_and_task(): void
    {
        $record = $this->backup(['backup' => 'completed', 'restore' => 'running']);
        $task = $this->task(RestoreBackup::class);

        (new RestoreBackup($record->id))->attachTask($task)->failed(new \RuntimeException('worker died'));

        $record->refresh();
        $this->assertSame('failed', $record->restoreStatus());
        $this->assertSame('worker died', $record->error);
        $this->assertSame('worker died', $task->refresh()->details['error']);
    }

    public function test_a_restore_job_failing_without_a_reason_still_says_why(): void
    {
        $record = $this->backup(['backup' => 'completed']);

        (new RestoreBackup($record->id))->failed(null);

        $this->assertSame('Restore job failed', $record->refresh()->error);
    }

    public function test_a_late_restore_failure_leaves_a_completed_restore_alone(): void
    {
        $record = $this->backup(['backup' => 'completed', 'restore' => 'completed']);
        $task = $this->task(RestoreBackup::class);
        $task->markCompleted();

        (new RestoreBackup($record->id))->attachTask($task)->failed(new \RuntimeException('late'));

        $this->assertSame('completed', $record->refresh()->restoreStatus());
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_a_delete_whose_owner_is_gone_fails_its_record(): void
    {
        $record = $this->orphan($this->backup(['backup' => 'completed']));
        $task = $this->task(DeleteBackup::class);

        $this->runJob((new DeleteBackup($record->id))->attachTask($task), 'Backup user not found');

        $record->refresh();
        $this->assertSame('failed', $record->deleteStatus());
        $this->assertSame('Backup user not found', $record->error);
    }

    public function test_a_delete_with_nothing_stored_removes_the_record(): void
    {
        $record = $this->backup(['backup' => 'completed']);
        $task = $this->task(DeleteBackup::class);

        (new DeleteBackup($record->id))->attachTask($task)->handle();

        $this->assertNull(BackupRecord::query()->find($record->id));
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_a_delete_the_storage_refused_keeps_the_record_and_raises_its_error(): void
    {
        $record = $this->backup(['backup' => 'completed']);
        $this->storeObjectsOf($record);
        $task = $this->task(DeleteBackup::class);

        $message = $this->runJob((new DeleteBackup($record->id))->attachTask($task));

        $record->refresh();
        $this->assertSame('failed', $record->deleteStatus());
        $this->assertStringStartsWith('Could not remove backup objects under', $message);
        $this->assertSame($record->error, $message);
    }

    public function test_a_failed_delete_job_fails_an_unfinished_task(): void
    {
        $record = $this->backup(['backup' => 'completed', 'delete' => 'running']);
        $task = $this->task(DeleteBackup::class);

        (new DeleteBackup($record->id))->attachTask($task)->failed(new \RuntimeException('worker died'));

        $this->assertSame('worker died', $task->refresh()->details['error']);
        $this->assertSame('failed', $record->refresh()->deleteStatus());
    }

    public function test_a_container_delete_removes_each_backup_then_the_container(): void
    {
        $record = $this->backup(['backup' => 'completed']);
        $task = $this->task(DeleteBackupContainer::class);

        (new DeleteBackupContainer($record->container_id))->attachTask($task)->handle();

        $this->assertNull(BackupRecord::query()->find($record->id));
        $this->assertNull(BackupContainer::query()->find($record->container_id));
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
    }

    public function test_a_container_delete_stops_at_a_backup_without_an_owner(): void
    {
        $record = $this->orphan($this->backup(['backup' => 'completed']));
        $task = $this->task(DeleteBackupContainer::class);

        $this->runJob((new DeleteBackupContainer($record->container_id))->attachTask($task), "Backup {$record->id} has no owning user");

        $this->assertNotNull(BackupContainer::query()->find($record->container_id));
        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }

    public function test_a_container_delete_stops_at_a_backup_the_storage_kept(): void
    {
        $record = $this->backup(['backup' => 'completed']);
        $this->storeObjectsOf($record);
        $task = $this->task(DeleteBackupContainer::class);

        $message = $this->runJob((new DeleteBackupContainer($record->container_id))->attachTask($task));

        $this->assertStringStartsWith('Could not remove backup objects under', $message);
        $this->assertNotNull(BackupRecord::query()->find($record->id));
        $this->assertNotNull(BackupContainer::query()->find($record->container_id));
    }

    public function test_a_failed_container_delete_job_fails_an_unfinished_task_once(): void
    {
        $task = $this->task(DeleteBackupContainer::class);

        (new DeleteBackupContainer(1))->attachTask($task)->failed(null);
        (new DeleteBackupContainer(1))->attachTask($task->refresh())->failed(new \RuntimeException('second'));

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Delete backup container job failed', $task->details['error']);
    }

    /** Runs a job expected to throw and returns the message it threw. */
    private function runJob(object $job, ?string $expected = null): string
    {
        try {
            $job->handle();
        } catch (\Throwable $e) {
            if ($expected !== null) {
                $this->assertSame($expected, $e->getMessage());
            }

            return $e->getMessage();
        }

        $this->fail('The job did not throw.');
    }

    /** @param array<string, string> $asyncStatus */
    private function backup(array $asyncStatus = ['backup' => 'pending']): BackupRecord
    {
        $user = User::findByUsername($this->username) ?? $this->makeUser($this->username);
        $container = BackupContainer::query()->first()
            ?? BackupContainer::create(['name' => 'disk', 'driver' => 'local', 'location' => $this->dir, 'credentials' => null]);

        return BackupRecord::create([
            'user_id' => $user->id,
            'username' => $this->username,
            'container_id' => $container->id,
            'async_status' => $asyncStatus,
        ]);
    }

    private function completedBackup(): BackupRecord
    {
        $record = $this->backup(['backup' => 'completed']);
        BackupItem::create([
            'backup_id' => $record->id,
            'remote_path' => 'files.tar.gz',
            'size_bytes' => 10,
            'details' => ['type' => 'files'],
        ]);

        return $record;
    }

    private function orphan(BackupRecord $record): BackupRecord
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('backups')->where('id', $record->id)->update(['user_id' => 9999]);
        DB::statement('PRAGMA foreign_keys = ON');

        return $record->refresh();
    }

    private function storeObjectsOf(BackupRecord $record): void
    {
        $prefix = $this->dir . '/' . BackupTransfer::prefix($record->container, $record->username, $record->id);
        mkdir($prefix, 0775, true);
        file_put_contents($prefix . 'files.tar.gz', 'archive');
    }

    private function task(string $jobType): Task
    {
        return Task::start(jobType: $jobType, queue: 'default', username: $this->username);
    }

    private function refuseEveryRootAction(): void
    {
        $client = $this->dir . '/pa-root';
        file_put_contents($client, "#!/bin/sh\necho 'Operation not permitted' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }
}
