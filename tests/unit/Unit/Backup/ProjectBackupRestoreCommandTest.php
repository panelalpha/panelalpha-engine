<?php

namespace Tests\Unit\Backup;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class ProjectBackupRestoreCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_restore_command_while_the_lock_is_held_prints_the_lock_message(): void
    {
        $user = $this->makeUser('alice', ['template' => 'dind']);
        $backup = $this->completedBackup($user);
        $lock = DeployLock::acquireFor($user->username, DeployLock::HOLDER_BACKUP);

        try {
            $this->artisan('project:backup:restore', [
                'project' => $user->username,
                'backup' => (string) $backup->id,
                '--force' => true,
            ])
                ->expectsOutputToContain('A backup is already running for this user.')
                ->assertExitCode(1);
        } finally {
            $lock->release();
            DeployLogger::deleteUserLogs($user->username);
        }

        $this->assertNull($backup->refresh()->restoreStatus());
    }

    private function completedBackup(User $user): BackupRecord
    {
        $container = BackupContainer::create([
            'name' => 'local',
            'driver' => 'local',
            'location' => sys_get_temp_dir() . '/pa-backup-cli-' . bin2hex(random_bytes(4)),
            'credentials' => null,
        ]);
        $backup = BackupRecord::prepare($user, $container, 'artisan');
        $backup->setBackupStatus('completed');
        $backup->save();

        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $user->username . '/' . $backup->id . '/project.tar.gz';
        $item->size_bytes = 1;
        $item->details = ['type' => 'files', 'name' => 'project'];
        $item->save();

        return $backup;
    }
}
