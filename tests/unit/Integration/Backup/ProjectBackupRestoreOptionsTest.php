<?php

namespace Tests\Integration\Backup;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * project:backup:restore: what it refuses before it starts, and how it reports a restore
 * that started and did not complete.
 */
class ProjectBackupRestoreOptionsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-restore-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $client = $this->root . '/pa-root';
        file_put_contents($client, "#!/bin/sh\necho 'Operation not permitted' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        DeployLogger::deleteUserLogs('alice');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->artisan('project:backup:restore', ['project' => 'ghost', 'backup' => '1', '--force' => true])
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_a_backup_of_another_project_is_refused(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $bobs = $this->completedBackup($this->makeUser('bob', ['template' => 'dind']), $this->localContainer());

        $this->artisan('project:backup:restore', ['project' => 'alice', 'backup' => (string) $bobs->id, '--force' => true])
            ->expectsOutputToContain("Backup '{$bobs->id}' not found for project 'alice'.")
            ->assertExitCode(1);

        $this->assertNull($bobs->fresh()->restoreStatus());
    }

    public function test_include_and_exclude_options_cannot_be_combined(): void
    {
        $record = $this->completedBackup($this->makeUser('alice', ['template' => 'dind']), $this->localContainer());

        $combinations = [
            ['--only-files' => true, '--exclude-files' => true],
            ['--only-volumes' => 'data, cache', '--exclude-databases' => 'shop'],
        ];
        foreach ($combinations as $options) {
            $this->artisan('project:backup:restore', ['project' => 'alice', 'backup' => (string) $record->id, '--force' => true, ...$options])
                ->expectsOutputToContain('Cannot combine include and exclude')
                ->assertExitCode(1);
        }

        $this->assertNull($record->fresh()->restoreStatus());
    }

    public function test_answering_no_starts_no_restore(): void
    {
        $record = $this->completedBackup($this->makeUser('alice', ['template' => 'dind']), $this->localContainer());

        $this->artisan('project:backup:restore', ['project' => 'alice', 'backup' => (string) $record->id])
            ->expectsConfirmation('Restore this backup?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertNull($record->fresh()->restoreStatus());
    }

    public function test_a_filter_no_item_matches_finishes_as_failed_with_the_reason(): void
    {
        $record = $this->completedBackup($this->makeUser('alice', ['template' => 'dind']), $this->localContainer());

        $this->artisan('project:backup:restore', [
            'project' => 'alice',
            'backup' => (string) $record->id,
            '--only-databases' => 'shop',
            '--force' => true,
        ])
            ->expectsOutputToContain('Restore finished (status: failed).')
            ->expectsOutputToContain('Error: No backup items match the restore filter')
            ->assertExitCode(1);

        $record->refresh();
        $this->assertSame('failed', $record->restoreStatus());
        $this->assertSame(['files' => false, 'volumes' => [], 'databases' => ['shop']], $record->restore_details['only']);
    }

    public function test_a_store_that_cannot_be_opened_is_reported(): void
    {
        $bucket = BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'bucket', 'credentials' => null]);
        $record = $this->completedBackup($this->makeUser('alice', ['template' => 'dind']), $bucket);

        $this->artisan('project:backup:restore', ['project' => 'alice', 'backup' => (string) $record->id, '--force' => true])
            ->expectsOutputToContain('Credentials are required for s3 backup storage.')
            ->assertExitCode(1);
    }

    private function localContainer(): BackupContainer
    {
        return BackupContainer::create([
            'name' => 'store-' . bin2hex(random_bytes(4)),
            'driver' => 'local',
            'location' => $this->root,
            'credentials' => null,
        ]);
    }

    private function completedBackup(User $user, BackupContainer $container): BackupRecord
    {
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $record->setBackupStatus('completed');
        $record->save();

        $item = new BackupItem();
        $item->backup_id = $record->id;
        $item->remote_path = "{$user->username}/{$record->id}/files.tar.gz";
        $item->size_bytes = 1;
        $item->details = ['type' => 'files', 'name' => 'files'];
        $item->save();

        return $record;
    }
}
