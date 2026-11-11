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

    public function test_create_command_requires_container_option(): void
    {
        $exitCode = $this->artisan('project:backup:create', [
            'project' => 'alice',
        ]);

        $exitCode->assertExitCode(1);
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
