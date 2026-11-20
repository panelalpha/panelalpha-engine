<?php

namespace Tests\Integration\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * project:backup:delete removes the backup's objects from the store, then its row. A local store
 * with nothing under the backup's prefix needs no root action; one with objects asks the broker.
 */
class ProjectBackupDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-delete-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->artisan('project:backup:delete', ['project' => 'ghost', 'backup' => '1', '--force' => true])
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);
    }

    public function test_a_backup_of_another_project_is_refused_and_kept(): void
    {
        $this->makeUser('alice');
        $bobs = $this->backup($this->makeUser('bob'));

        $this->artisan('project:backup:delete', ['project' => 'alice', 'backup' => (string) $bobs->id, '--force' => true])
            ->expectsOutputToContain("Backup '{$bobs->id}' not found for project 'alice'.")
            ->assertExitCode(1);

        $this->assertNotNull($bobs->fresh());
    }

    public function test_without_a_terminal_or_force_nothing_is_deleted(): void
    {
        $record = $this->backup($this->makeUser('alice'));

        $this->artisan("project:backup:delete --no-interaction alice {$record->id}")
            ->expectsOutputToContain('This change needs confirmation: pass --force to apply it without a prompt.')
            ->assertExitCode(1);

        $this->assertNotNull($record->fresh());
    }

    public function test_answering_no_keeps_the_backup(): void
    {
        $record = $this->backup($this->makeUser('alice'));

        $this->artisan('project:backup:delete', ['project' => 'alice', 'backup' => (string) $record->id])
            ->expectsConfirmation('Delete this backup?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertNotNull($record->fresh());
    }

    public function test_a_confirmed_delete_removes_the_row(): void
    {
        $record = $this->backup($this->makeUser('alice'));

        $this->artisan('project:backup:delete', ['project' => 'alice', 'backup' => (string) $record->id])
            ->expectsConfirmation('Delete this backup?', 'yes')
            ->expectsOutputToContain('Backup deleted successfully.')
            ->assertExitCode(0);

        $this->assertNull(BackupRecord::query()->find($record->id));
    }

    public function test_a_store_that_refuses_the_delete_keeps_the_row_and_prints_why(): void
    {
        $record = $this->backup($this->makeUser('alice'));
        mkdir("{$this->root}/alice/{$record->id}", 0777, true);
        file_put_contents("{$this->root}/alice/{$record->id}/files.tar.gz", 'stub');
        $this->refuseEveryRootAction();

        $this->artisan('project:backup:delete', ['project' => 'alice', 'backup' => (string) $record->id, '--force' => true])
            ->expectsOutputToContain("Could not remove backup objects under alice/{$record->id}/: Operation not permitted")
            ->assertExitCode(1);

        $record->refresh();
        $this->assertSame('failed', $record->deleteStatus());
        $this->assertFileExists("{$this->root}/alice/{$record->id}/files.tar.gz");
    }

    private function backup(User $user): BackupRecord
    {
        $container = BackupContainer::create([
            'name' => 'store-' . bin2hex(random_bytes(4)),
            'driver' => 'local',
            'location' => $this->root,
            'credentials' => null,
        ]);
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $record->setBackupStatus('completed');
        $record->save();

        return $record;
    }

    private function refuseEveryRootAction(): void
    {
        $client = $this->root . '/pa-root';
        file_put_contents($client, "#!/bin/sh\necho 'Operation not permitted' >&2\nexit 1\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }
}
