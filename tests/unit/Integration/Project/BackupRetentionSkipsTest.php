<?php

namespace Tests\Integration\Project;

use App\Jobs\DeleteBackup;
use App\Lib\Project\BackupRetention;
use App\Models\Backup;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Retention prunes only after a scheduled backup of a project that still has a schedule: a manual backup, or a
 * project whose schedule is gone, deletes nothing.
 */
class BackupRetentionSkipsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Queue::fake();
    }

    public function test_a_manual_backup_prunes_nothing(): void
    {
        (new BackupRetention)->prune($this->backup('manual'));

        Queue::assertNotPushed(DeleteBackup::class);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_scheduled_backup_of_a_project_without_a_schedule_prunes_nothing(): void
    {
        (new BackupRetention)->prune($this->backup('scheduled'));

        Queue::assertNotPushed(DeleteBackup::class);
        $this->assertSame(0, Task::query()->count());
    }

    private function backup(string $kind): Backup
    {
        $backup = new Backup;
        $backup->kind = $kind;
        $backup->user_id = $this->makeUser('shop'.$kind)->id;

        return $backup;
    }
}
