<?php

namespace Tests\Unit\Backup;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class ProjectBackupCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_create_command_without_a_container_on_a_host_without_a_default_names_the_option(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:backup:create', ['project' => 'alice'])
            ->expectsOutputToContain('no default backup container; name one with --container')
            ->assertExitCode(1);

        $this->assertSame(0, BackupRecord::query()->count());
    }

    public function test_create_command_without_a_container_backs_up_into_the_default_one(): void
    {
        $user = $this->makeUser('alice', ['template' => 'dind']);
        $container = BackupContainer::create([
            'name' => 'local',
            'driver' => 'local',
            'location' => sys_get_temp_dir() . '/pa-backup-cli-' . bin2hex(random_bytes(4)),
            'credentials' => null,
        ]);
        $container->makeDefault();
        $lock = DeployLock::acquireFor($user->username, DeployLock::HOLDER_BACKUP);

        try {
            $this->artisan('project:backup:create', ['project' => $user->username])
                ->expectsOutputToContain('A backup is already running for this user.')
                ->doesntExpectOutputToContain('no default backup container')
                ->assertExitCode(1);
        } finally {
            $lock->release();
            DeployLogger::deleteUserLogs($user->username);
        }
    }

    public function test_create_command_while_the_lock_is_held_leaves_no_backup_row(): void
    {
        $user = $this->makeUser('alice', ['template' => 'dind']);
        $container = BackupContainer::create([
            'name' => 'local',
            'driver' => 'local',
            'location' => sys_get_temp_dir() . '/pa-backup-cli-' . bin2hex(random_bytes(4)),
            'credentials' => null,
        ]);
        $lock = DeployLock::acquireFor($user->username, DeployLock::HOLDER_BACKUP);

        try {
            $this->artisan('project:backup:create', [
                'project' => $user->username,
                '--container' => (string) $container->id,
            ])
                ->expectsOutputToContain('A backup is already running for this user.')
                ->assertExitCode(1);
        } finally {
            $lock->release();
            DeployLogger::deleteUserLogs($user->username);
        }

        $this->assertSame(0, BackupRecord::query()->count());
    }
}
