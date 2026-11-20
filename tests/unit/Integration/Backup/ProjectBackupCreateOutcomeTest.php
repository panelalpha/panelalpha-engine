<?php

namespace Tests\Integration\Backup;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * project:backup:create: the refusals before a backup row exists, and the report of a backup
 * that ran and failed. Every root action is refused, so nothing reaches the host.
 */
class ProjectBackupCreateOutcomeTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-create-cli-' . bin2hex(random_bytes(4));
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
        $this->artisan('project:backup:create', ['project' => 'ghost'])
            ->expectsOutputToContain("Project 'ghost' not found.")
            ->assertExitCode(1);

        $this->assertSame(0, BackupRecord::query()->count());
    }

    public function test_an_unknown_container_is_refused(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:backup:create', ['project' => 'alice', '--container' => 'nope'])
            ->expectsOutputToContain("Backup container 'nope' not found.")
            ->assertExitCode(1);

        $this->assertSame(0, BackupRecord::query()->count());
    }

    public function test_a_store_that_cannot_be_opened_is_reported(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'bucket', 'credentials' => null]);

        $this->artisan('project:backup:create', ['project' => 'alice', '--container' => 'bucket'])
            ->expectsOutputToContain('Credentials are required for s3 backup storage.')
            ->assertExitCode(1);
    }

    public function test_a_backup_whose_store_cannot_be_opened_is_marked_failed(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'bucket', 'credentials' => null]);

        $this->artisan('project:backup:create', ['project' => 'alice', '--container' => 'bucket'])
            ->expectsOutputToContain('status: failed).')
            ->assertExitCode(1);

        $record = BackupRecord::query()->sole();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('Credentials are required for s3 backup storage.', $record->error);
    }

    public function test_a_backup_the_host_refuses_is_reported_as_failed_with_its_error(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $container = BackupContainer::create(['name' => 'disk', 'driver' => 'local', 'location' => $this->root . '/store', 'credentials' => null]);

        $this->artisan('project:backup:create', ['project' => 'alice', '--container' => 'disk'])
            ->expectsOutputToContain('status: failed).')
            ->expectsOutputToContain('Error: Operation not permitted')
            ->assertExitCode(1);

        $record = BackupRecord::query()->sole();
        $this->assertSame($container->id, $record->container_id);
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('Operation not permitted', trim($record->error));
        $this->assertSame(0, $record->items()->count());
    }
}
