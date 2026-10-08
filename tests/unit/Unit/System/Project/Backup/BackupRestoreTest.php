<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-sys-backup-restore-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_containers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver');
            $table->string('location', 1024);
            $table->longText('credentials')->nullable();
            $table->timestamps();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username')->index();
            $table->foreignId('container_id')->constrained('backup_containers')->restrictOnDelete();
            $table->json('async_status')->nullable();
            $table->json('restore_details')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->string('remote_path', 1024);
            $table->unsignedBigInteger('size_bytes');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['backup_id', 'remote_path']);
        });
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_non_dind_template_is_not_supported(): void
    {
        $user = $this->makeUser('alice', template: 'default');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $backup = $this->seedCompletedBackup($user, $container);
        $double = new BackupDouble($this->project($user), $backup, $storage);

        try {
            $double->restore();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('not supported', $e->getMessage());
        }

        $this->assertNotContains('composeStop', $double->calls);
    }

    public function test_incomplete_backup_cannot_restore(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();

        $backup = new BackupRecord();
        $backup->user_id = $user->id;
        $backup->username = $user->username;
        $backup->container_id = $container->id;
        $backup->async_status = ['backup' => 'pending'];
        $backup->save();

        $double = new BackupDouble($this->project($user), $backup, $storage);

        try {
            $double->restore();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Backup is not complete', $e->getMessage());
        }

        $this->assertNotContains('composeStop', $double->calls);
    }

    public function test_sha256_mismatch_aborts_before_host_restore(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $double = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $double->model()->fresh(['items']);

        foreach ($backup->items as $item) {
            $storage->readStreamOverrides[$item->remote_path] = 'tampered-content';
        }

        $restore = new BackupDouble($this->project($user), $backup, $storage);
        $restore->restore();

        $backup->refresh();
        $this->assertSame('failed', $backup->restoreStatus());
        $this->assertStringContainsString('Checksum mismatch', (string) $backup->error);
        $this->assertNotContains('swapProject:project.incoming:project.pre-restore', $restore->calls);
        $this->assertNotContains('extractProjectArchive:project.incoming', $restore->calls);
        $this->assertFalse($this->hostCallsContain($restore->calls, 'restoreVolume'));
        $this->assertContains('composeStop', $restore->calls);
        $this->assertContains('composeStart', $restore->calls);
    }

    public function test_happy_path_restores_all_components(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);

        $restore = new BackupDouble($this->project($user), $backup, $storage);
        $restore->restore();

        $backup->refresh();
        $this->assertSame('completed', $backup->restoreStatus());
        $this->assertNull($backup->error);
        $this->assertContains('extractProjectArchive:project.incoming', $restore->calls);
        $this->assertContains('swapProject:project.incoming:project.pre-restore', $restore->calls);
        $this->assertContains('ensureVolume:proj_dbdata', $restore->calls);
        $this->assertContains('restoreVolume:proj_dbdata', $restore->calls);
        $this->assertContains('dumpDatabase', $restore->calls);
        $this->assertContains('restoreDatabase:alice_app', $restore->calls);
        $this->assertContains('discardProjectAside:project.pre-restore', $restore->calls);
        $this->assertContains('composeStart', $restore->calls);

        $extractIndex = array_search('extractProjectArchive:project.incoming', $restore->calls, true);
        $swapIndex = array_search('swapProject:project.incoming:project.pre-restore', $restore->calls, true);
        $volumeIndex = array_search('restoreVolume:proj_dbdata', $restore->calls, true);
        $startIndex = array_search('composeStart', $restore->calls, true);
        $this->assertNotFalse($extractIndex);
        $this->assertNotFalse($swapIndex);
        $this->assertNotFalse($volumeIndex);
        $this->assertNotFalse($startIndex);
        $this->assertLessThan($swapIndex, $extractIndex);
        $this->assertLessThan($volumeIndex, $swapIndex);
        $this->assertLessThan($startIndex, $volumeIndex);
    }

    public function test_swap_failure_with_successful_rollback_starts_compose(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);

        $restore = new BackupDouble(
            $this->project($user),
            $backup,
            $storage,
            swapProjectThrows: true,
        );
        $restore->restore();

        $backup->refresh();
        $this->assertSame('failed', $backup->restoreStatus());
        $this->assertContains('rollbackProject:project.pre-restore', $restore->calls);
        $this->assertContains('composeStart', $restore->calls);
    }

    public function test_swap_failure_with_failed_rollback_leaves_stack_stopped(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);

        $restore = new BackupDouble(
            $this->project($user),
            $backup,
            $storage,
            swapProjectThrows: true,
            rollbackProjectThrows: true,
        );
        $restore->restore();

        $backup->refresh();
        $this->assertSame('failed', $backup->restoreStatus());
        $this->assertStringContainsString('project.pre-restore', (string) $backup->error);
        $this->assertContains('composeStop', $restore->calls);
        $this->assertNotContains('composeStart', $restore->calls);
    }

    public function test_cannot_combine_include_and_exclude(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);

        $restore = new BackupDouble($this->project($user), $backup, $storage);
        try {
            $restore->restore(['files' => true], ['files' => true]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot combine include and exclude', $e->getMessage());
        }

        $this->assertNotContains('composeStop', $restore->calls);
    }

    public function test_volume_restore_failure_rolls_back_project_and_volume(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->makeCompletedBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);

        $restore = new BackupDouble(
            $this->project($user),
            $backup,
            $storage,
            restoreVolumeThrows: true,
        );
        $restore->restore();

        $backup->refresh();
        $this->assertSame('failed', $backup->restoreStatus());
        $this->assertContains('rollbackVolume:proj_dbdata', $restore->calls);
        $this->assertContains('rollbackProject:project.pre-restore', $restore->calls);
        $this->assertContains('composeStart', $restore->calls);
    }

    /**
     * @param list<string> $calls
     */
    private function hostCallsContain(array $calls, string $needle): bool
    {
        foreach ($calls as $call) {
            if (str_starts_with($call, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function makeCompletedBackup(
        User $user,
        BackupContainer $container,
        FakeStorage $storage,
    ): BackupDouble {
        $project = $this->project($user);
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $backup = new BackupDouble($project, $record, $storage);

        try {
            $backup->run();
        } catch (\Throwable) {
            $record->refresh();
        }

        return $backup;
    }

    private function seedCompletedBackup(User $user, BackupContainer $container): BackupRecord
    {
        $backup = new BackupRecord();
        $backup->user_id = $user->id;
        $backup->username = $user->username;
        $backup->container_id = $container->id;
        $backup->async_status = ['backup' => 'completed'];
        $backup->save();

        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $user->username . '/' . $backup->id . '/files.tar.gz';
        $item->size_bytes = 1024;
        $item->details = [
            'type' => 'files',
            'name' => 'files',
            'sha256' => hash('sha256', 'stub'),
        ];
        $item->save();

        return $backup->fresh(['items']);
    }

    private function project(User $user): Project
    {
        return new Project($this->systemWithFreeSpace(), $user);
    }

    private function systemWithFreeSpace(): System
    {
        $engineRoot = $this->tmpRoot;

        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function assertFreeSpace(int $bytes): void
                    {
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new class () extends Process {
                    public function __construct()
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function getOutput(): string
                    {
                        return '';
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }
                };
            }
        };
    }

    private function makeUser(string $username, ?string $template = 'dind'): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username . '.example.test';
        $user->email = $username . '@example.test';
        $user->name = $username;
        $user->status = 'active';
        $user->details = $template !== null ? ['template' => $template] : [];
        $user->save();

        return $user;
    }

    private function makeContainer(array $credentials = []): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'test-' . uniqid('', true);
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = $credentials !== [] ? $credentials : null;
        $container->save();

        return $container;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
