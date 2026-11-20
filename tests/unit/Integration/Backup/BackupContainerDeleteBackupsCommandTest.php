<?php

namespace Tests\Integration\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * backup:container:delete on a store that still holds backups: refused without --delete-backups,
 * and with it every backup goes first, the store only once none is left.
 */
class BackupContainerDeleteBackupsCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-container-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_unknown_container_is_refused(): void
    {
        $this->artisan('backup:container:delete --no-interaction missing --force')
            ->expectsOutputToContain("Backup container 'missing' not found.")
            ->assertExitCode(1);
    }

    public function test_a_container_with_backups_needs_the_delete_backups_option(): void
    {
        $box = $this->localContainer();
        $this->backupIn($box, 'alice');

        $this->artisan("backup:container:delete --no-interaction {$box->id} --force")
            ->expectsOutputToContain('--delete-backups')
            ->assertExitCode(1);

        $this->assertNotNull($box->fresh());
        $this->assertSame(1, BackupRecord::query()->count());
    }

    public function test_without_a_terminal_or_force_nothing_is_deleted(): void
    {
        $box = $this->localContainer();
        $this->backupIn($box, 'alice');

        $this->artisan("backup:container:delete --no-interaction {$box->id} --delete-backups")
            ->expectsOutputToContain('This change needs confirmation: pass --force to apply it without a prompt.')
            ->assertExitCode(1);

        $this->assertNotNull($box->fresh());
        $this->assertSame(1, BackupRecord::query()->count());
    }

    public function test_answering_no_keeps_the_container(): void
    {
        $box = $this->localContainer();

        $this->artisan("backup:container:delete {$box->id}")
            ->expectsOutputToContain("Name: {$box->name}")
            ->expectsOutputToContain('Driver: local')
            ->expectsOutputToContain("Location: {$this->root}")
            ->expectsConfirmation('Delete this container?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertNotNull($box->fresh());
    }

    public function test_delete_backups_removes_every_backup_then_the_container(): void
    {
        $box = $this->localContainer($this->root . '/never-written');
        $kept = $this->localContainer();
        $this->backupIn($box, 'alice');
        $this->backupIn($box, 'bob');
        $other = $this->backupIn($kept, 'carol');

        $this->artisan("backup:container:delete --no-interaction {$box->id} --delete-backups --force")
            ->expectsOutputToContain('Backup container deleted successfully.')
            ->assertExitCode(0);

        $this->assertNull($box->fresh());
        $this->assertSame([$other->id], BackupRecord::query()->pluck('id')->all());
    }

    public function test_a_backup_whose_store_cannot_be_opened_stops_the_delete(): void
    {
        $bucket = BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'bucket', 'credentials' => null]);
        $this->backupIn($bucket, 'alice');

        $this->artisan("backup:container:delete --no-interaction {$bucket->id} --delete-backups --force")
            ->expectsOutputToContain('Credentials are required for s3 backup storage.')
            ->assertExitCode(1);

        $this->assertNotNull($bucket->fresh());
        $this->assertSame(1, BackupRecord::query()->count());
    }

    public function test_a_backup_the_store_refuses_to_remove_keeps_the_container(): void
    {
        $box = $this->localContainer();
        $record = $this->backupIn($box, 'alice');
        mkdir("{$this->root}/alice/{$record->id}", 0777, true);
        file_put_contents("{$this->root}/alice/{$record->id}/files.tar.gz", 'stub');
        $this->refuseEveryRootAction();

        $this->artisan("backup:container:delete --no-interaction {$box->id} --delete-backups --force")
            ->expectsOutputToContain("Could not remove backup objects under alice/{$record->id}/: Operation not permitted")
            ->assertExitCode(1);

        $this->assertNotNull($box->fresh());
        $this->assertSame('failed', $record->fresh()->deleteStatus());
    }

    private function localContainer(?string $location = null): BackupContainer
    {
        return BackupContainer::create([
            'name' => 'store-' . bin2hex(random_bytes(4)),
            'driver' => 'local',
            'location' => $location ?? $this->root,
            'credentials' => null,
        ]);
    }

    private function backupIn(BackupContainer $container, string $username): BackupRecord
    {
        $user = User::query()->where('username', $username)->first() ?? $this->makeUser($username);
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
