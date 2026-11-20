<?php

namespace Tests\Integration\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\BackupSchedule;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What project:backup:download and project:backup:schedule refuse, and how a schedule
 * that already exists is replaced.
 */
class ProjectBackupDownloadScheduleRefusalsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-download-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_download_refuses_an_unknown_project(): void
    {
        $this->artisan('project:backup:download ghost 1 1')
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_download_refuses_a_backup_of_another_project(): void
    {
        $this->makeUser('alice');
        $bobs = $this->backup($this->makeUser('bob'), 'completed');

        $this->artisan("project:backup:download alice {$bobs->id} {$bobs->items->first()->id}")
            ->expectsOutputToContain("Backup '{$bobs->id}' not found for project 'alice'.")
            ->assertExitCode(1);
    }

    public function test_download_refuses_a_backup_that_did_not_complete(): void
    {
        $record = $this->backup($this->makeUser('alice'), 'running');

        $this->artisan("project:backup:download alice {$record->id} {$record->items->first()->id}")
            ->expectsOutputToContain('Backup is not complete')
            ->assertExitCode(1);
    }

    public function test_download_to_a_path_that_cannot_be_written_fails(): void
    {
        $record = $this->backup($this->makeUser('alice'), 'completed');
        $output = $this->root . '/missing-dir/home.tar.gz';

        $this->artisan("project:backup:download alice {$record->id} {$record->items->first()->id} --output={$output}")
            ->expectsOutputToContain("Could not open '{$output}' for writing.")
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($output);
    }

    public function test_schedule_refuses_an_unknown_project(): void
    {
        $this->artisan("project:backup:schedule ghost --cron='0 3 * * *'")
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_disabling_a_project_without_a_schedule_changes_nothing(): void
    {
        $this->makeUser('alice');

        $this->artisan('project:backup:schedule alice --disable')
            ->expectsOutputToContain('No schedule to disable.')
            ->assertExitCode(0);

        $this->assertSame(0, BackupSchedule::query()->count());
    }

    public function test_schedule_refuses_a_missing_or_invalid_cron_and_keep(): void
    {
        $this->makeUser('alice');
        $container = $this->container();

        $cases = [
            ["--container={$container->id}", 'The --cron option is required.'],
            ["--container={$container->id} --cron='not a cron'", 'Invalid cron expression.'],
            ["--container={$container->id} --cron='0 3 * * *' --keep=0", 'The --keep option must be between 1 and 365.'],
            ["--container={$container->id} --cron='0 3 * * *' --keep=366", 'The --keep option must be between 1 and 365.'],
            ["--container={$container->id} --cron='0 3 * * *' --keep=many", 'The --keep option must be between 1 and 365.'],
            ["--container=nope --cron='0 3 * * *'", "Backup container 'nope' not found."],
        ];
        foreach ($cases as [$args, $message]) {
            $this->artisan("project:backup:schedule alice {$args}")
                ->expectsOutputToContain($message)
                ->assertExitCode(1);
        }

        $this->assertSame(0, BackupSchedule::query()->count());
    }

    public function test_a_new_cron_keeps_the_store_and_retention_of_the_existing_schedule(): void
    {
        $user = $this->makeUser('alice');
        $current = $this->container();
        $default = $this->container();
        $default->makeDefault();
        BackupSchedule::create([
            'user_id' => $user->id,
            'container_id' => $current->id,
            'cron' => '0 3 * * *',
            'keep_last' => 12,
            'enabled' => false,
            'last_error' => 'store was full',
        ]);

        $this->artisan("project:backup:schedule alice --cron='30 4 * * 1'")
            ->expectsOutputToContain('Schedule set (cron: 30 4 * * 1, next run: ')
            ->assertExitCode(0);

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame($current->id, $schedule->container_id);
        $this->assertSame('30 4 * * 1', $schedule->cron);
        $this->assertSame(12, $schedule->keep_last);
        $this->assertTrue($schedule->enabled);
        $this->assertNull($schedule->last_error);
        $this->assertNotNull($schedule->next_run_at);
    }

    private function container(): BackupContainer
    {
        return BackupContainer::create([
            'name' => 'store-' . bin2hex(random_bytes(4)),
            'driver' => 'local',
            'location' => $this->root,
            'credentials' => null,
        ]);
    }

    private function backup(User $user, string $status): BackupRecord
    {
        $record = BackupRecord::prepare($user, $this->container(), 'artisan');
        $record->setBackupStatus($status);
        $record->save();

        $key = "{$user->username}/{$record->id}/home.tar.gz";
        mkdir(dirname($this->root . '/' . $key), 0777, true);
        file_put_contents($this->root . '/' . $key, 'stub-home');

        $item = new BackupItem();
        $item->backup_id = $record->id;
        $item->remote_path = $key;
        $item->size_bytes = strlen('stub-home');
        $item->details = ['type' => 'files', 'name' => 'home'];
        $item->save();

        return $record->fresh(['container', 'items']);
    }
}
