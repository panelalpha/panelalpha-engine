<?php

namespace Tests\Unit\Backup;

use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Lib\Project\ProjectRebuild;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class RunScheduledCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        Queue::fake();
    }

    public function test_a_due_schedule_creates_one_scheduled_backup_and_advances(): void
    {
        $schedule = $this->dueSchedule();

        $this->artisan('backups:run-scheduled')->assertExitCode(0);

        $backup = BackupRecord::query()->firstOrFail();
        $this->assertSame('scheduled', $backup->kind);
        $this->assertSame(['files' => true, 'databases' => true], $backup->contents);
        Queue::assertPushed(CreateBackup::class, 1);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertSame($backup->id, $schedule->last_backup_id);
        $this->assertTrue($schedule->next_run_at->isFuture());
        $this->assertNull($schedule->last_error);

        $this->artisan('backups:run-scheduled')->assertExitCode(0);
        $this->assertSame(1, BackupRecord::query()->count());
    }

    public function test_a_busy_project_is_retried_next_minute(): void
    {
        $schedule = $this->dueSchedule();
        $before = $schedule->next_run_at->timestamp;
        $this->bindBusyProject();

        $this->artisan('backups:run-scheduled')->assertExitCode(0);

        $this->assertSame(0, BackupRecord::query()->count());
        $schedule->refresh();
        $this->assertSame($before, $schedule->next_run_at->timestamp);
    }

    public function test_a_project_busy_for_an_hour_gives_up_for_this_occurrence(): void
    {
        $schedule = $this->dueSchedule(120);
        $this->bindBusyProject();

        $this->artisan('backups:run-scheduled')->assertExitCode(0);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_error);
        $this->assertTrue($schedule->next_run_at->isFuture());
    }

    private function dueSchedule(int $minutesAgo = 1): BackupSchedule
    {
        $user = $this->makeUser('alice', ['template' => 'default']);
        $container = $this->localContainer();

        $schedule = new BackupSchedule();
        $schedule->user_id = $user->id;
        $schedule->container_id = $container->id;
        $schedule->cron = '0 3 * * *';
        $schedule->keep_last = 3;
        $schedule->files = true;
        $schedule->databases = true;
        $schedule->enabled = true;
        $schedule->next_run_at = now('UTC')->subMinutes($minutesAgo);
        $schedule->save();

        return $schedule;
    }

    private function bindBusyProject(): void
    {
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild {
            public function __construct()
            {
            }

            public function assertIdle(User $user): void
            {
                throw ProjectBusyException::pushRunning($user->username);
            }
        });
    }

    private function localContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'store-' . bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = sys_get_temp_dir() . '/pa-run-scheduled';
        $container->credentials = null;
        $container->save();

        return $container;
    }
}
