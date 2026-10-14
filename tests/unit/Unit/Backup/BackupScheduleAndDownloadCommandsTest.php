<?php

namespace Tests\Unit\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\BackupSchedule;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class BackupScheduleAndDownloadCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_download_writes_the_item_to_the_output_path(): void
    {
        $user = $this->makeUser('alice', ['template' => 'default']);
        $backup = $this->completedBackup($user);

        $output = $this->root . '/home.tar.gz';
        $this->artisan(
            "project:backup:download alice {$backup->id} {$backup->items->first()->id} --output={$output}"
        )->assertExitCode(0);

        $this->assertSame('stub-home', file_get_contents($output));
    }

    public function test_download_answers_a_missing_item_with_a_plain_failure(): void
    {
        $user = $this->makeUser('alice', ['template' => 'default']);
        $backup = $this->completedBackup($user);

        $this->artisan("project:backup:download alice {$backup->id} 999999")
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_schedule_command_creates_then_disables(): void
    {
        $user = $this->makeUser('alice', ['template' => 'default']);
        $container = $this->localContainer();

        $this->artisan(
            "project:backup:schedule alice --container={$container->id} --cron='0 3 * * *' --keep=3"
        )->assertExitCode(0);

        $schedule = BackupSchedule::query()->firstOrFail();
        $this->assertSame('0 3 * * *', $schedule->cron);
        $this->assertSame(3, $schedule->keep_last);
        $this->assertTrue($schedule->enabled);
        $this->assertNotNull($schedule->next_run_at);

        $this->artisan('project:backup:schedule alice --disable')->assertExitCode(0);
        $this->assertFalse($schedule->fresh()->enabled);
    }

    public function test_schedule_command_refuses_a_too_frequent_cron(): void
    {
        $this->makeUser('alice', ['template' => 'default']);
        $container = $this->localContainer();

        $this->artisan("project:backup:schedule alice --container={$container->id} --cron='* * * * *'")
            ->expectsOutputToContain('at most once an hour')
            ->assertExitCode(1);

        $this->assertSame(0, BackupSchedule::query()->count());
    }

    private function localContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'store-' . bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = $this->root;
        $container->credentials = null;
        $container->save();

        return $container;
    }

    private function completedBackup(User $user): BackupRecord
    {
        $container = $this->localContainer();

        $backup = BackupRecord::prepare($user, $container, 'artisan');
        $backup->setBackupStatus('completed');
        $backup->save();

        $key = $user->username . '/' . $backup->id . '/home.tar.gz';
        $path = $this->root . '/' . $key;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'stub-home');

        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $key;
        $item->size_bytes = strlen('stub-home');
        $item->details = ['type' => 'files', 'name' => 'home', 'scope' => 'home'];
        $item->save();

        return $backup->fresh(['container', 'items']);
    }
}
