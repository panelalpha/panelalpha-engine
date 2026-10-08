<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupDeleteTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-sys-backup-delete-' . bin2hex(random_bytes(4));
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

    public function test_storage_delete_prefix_failure_keeps_row(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage(deletePrefixFailureMessage: 'storage delete failed');
        $created = $this->createBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);
        $backupId = $backup->id;

        $deleter = new BackupDouble($this->project($user), $backup, $storage);
        $deleter->delete();

        $this->assertNotNull(BackupRecord::query()->find($backupId));
        $row = BackupRecord::query()->find($backupId);
        $this->assertSame('failed', $row->deleteStatus());
        $this->assertSame('storage delete failed', $row->error);
        $this->assertSame(3, $backup->items()->count());
    }

    public function test_happy_delete_removes_row_and_storage_prefix(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $created = $this->createBackup($user, $container, $storage);
        $backup = $created->model()->fresh(['items']);
        $backupId = $backup->id;

        $deleter = new BackupDouble($this->project($user), $backup, $storage);
        $deleter->delete();

        $this->assertNull(BackupRecord::query()->find($backupId));
        $this->assertSame(['alice/' . $backupId . '/'], $storage->deletePrefixCalls);
    }

    public function test_delete_all_backups_deletes_every_backup(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $project = $this->project($user);

        $first = $this->createBackup($user, $container, $storage)->model();
        $second = $this->createBackup($user, $container, $storage)->model();

        $this->deleteAllBackups($project, $user, $storage);

        $this->assertSame(0, BackupRecord::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            ['alice/' . $first->id . '/', 'alice/' . $second->id . '/'],
            $storage->deletePrefixCalls,
        );
    }

    public function test_delete_all_backups_stops_on_first_storage_failure(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage(
            deletePrefixFailureMessage: 'storage delete failed',
            failDeletePrefixOn: 1,
        );
        $project = $this->project($user);

        $first = $this->createBackup($user, $container, new FakeStorage())->model();
        // Re-run create into failing storage's object map isn't needed — delete uses deletePrefix.
        $second = $this->createBackup($user, $container, new FakeStorage())->model();

        // Point both records at the failing storage via BackupDouble.
        try {
            foreach ([$first, $second] as $record) {
                $backupId = $record->id;
                (new BackupDouble($project, $record->fresh(['container']), $storage))->delete();
                if (BackupRecord::query()->find($backupId) !== null) {
                    throw new \RuntimeException(
                        "Backup {$backupId} storage delete failed",
                    );
                }
            }
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $first->id, $e->getMessage());
            $this->assertStringContainsString('storage delete failed', $e->getMessage());
        }

        $this->assertNotNull(BackupRecord::query()->find($first->id));
        $this->assertNotNull(BackupRecord::query()->find($second->id));
        $this->assertSame('failed', BackupRecord::query()->find($first->id)?->deleteStatus());
        $this->assertSame(1, count($storage->deletePrefixCalls));
    }

    private function createBackup(User $user, BackupContainer $container, FakeStorage $storage): BackupDouble
    {
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

    private function deleteAllBackups(Project $project, User $user, FakeStorage $storage): void
    {
        foreach ($user->backups()->with('container')->get() as $record) {
            $backupId = $record->id;
            (new BackupDouble($project, $record, $storage))->delete();

            if (BackupRecord::query()->find($backupId) !== null) {
                throw new \RuntimeException(
                    "Backup {$backupId} storage delete failed",
                );
            }
        }
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

    private function makeUser(string $username): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username . '.example.test';
        $user->email = $username . '@example.test';
        $user->name = $username;
        $user->status = 'active';
        $user->details = ['template' => 'dind'];
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
