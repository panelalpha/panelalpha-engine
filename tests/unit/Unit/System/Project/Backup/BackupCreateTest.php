<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\Backup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupCreateTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-sys-backup-' . bin2hex(random_bytes(4));
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

    public function test_project_backup_factory_returns_backup(): void
    {
        $user = $this->makeUser('alice');
        $project = $this->project($user);
        $container = $this->makeContainer();
        $record = BackupRecord::prepare($user, $container, 'artisan');

        $backup = $project->backup($record);

        $this->assertInstanceOf(Backup::class, $backup);
        $this->assertSame($record->id, $backup->model()->id);
    }

    public function test_user_id_mismatch_is_not_found(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $container = $this->makeContainer();
        $record = BackupRecord::prepare($bob, $container, 'artisan');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup not found');

        $this->project($alice)->backup($record);
    }

    public function test_non_dind_template_is_not_supported(): void
    {
        $user = $this->makeUser('alice', template: 'default');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $project = $this->project($user);
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $backup = new BackupDouble($project, $record, $storage);

        try {
            $backup->run();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('not supported', $e->getMessage());
        }

        $record->refresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertSame('not supported', $record->error);
        $this->assertNotContains('composeStop', $backup->calls);
        $this->assertSame(1, BackupRecord::query()->count());
    }

    public function test_happy_path_creates_backup_with_items(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $backup = $this->createBackup($user, $container, $storage);

        $stopIndex = array_search('composeStop', $backup->calls, true);
        $startIndex = array_search('composeStart', $backup->calls, true);

        $this->assertNotFalse($stopIndex);
        $this->assertNotFalse($startIndex);
        $this->assertLessThan($startIndex, $stopIndex);
        $this->assertSame('composeStart', $backup->calls[array_key_last($backup->calls)]);
        $this->assertSame(['put', 'put', 'put'], $storage->calls);
        $this->assertContains('tarProject', $backup->calls);
        $this->assertContains('tarVolume', $backup->calls);
        $this->assertContains('dumpDatabase', $backup->calls);

        $record = $backup->model()->fresh(['items']);
        $this->assertSame('completed', $record->backupStatus());
        $this->assertNull($record->error);
        $this->assertSame(3, $record->items()->count());

        $paths = $record->items()->orderBy('remote_path')->pluck('remote_path')->all();
        $this->assertSame([
            'alice/1/database-alice_app.sql.gz',
            'alice/1/files.tar.gz',
            'alice/1/volume-dbdata.tar.gz',
        ], $paths);

        foreach ($record->items as $item) {
            $this->assertSame(64, strlen((string) ($item->details['sha256'] ?? '')));
        }

        $this->assertSame(3, count($storage->putKeys));
    }

    public function test_put_failure_marks_backup_failed_and_cleans_up(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage(failPutOn: 2);
        $backup = $this->createBackup($user, $container, $storage);

        $record = $backup->model()->fresh();
        $this->assertSame('failed', $record->backupStatus());
        $this->assertNotNull($record->error);
        $this->assertSame(0, BackupItem::query()->count());
        $this->assertSame(['alice/1/'], $storage->deletePrefixCalls);
        $this->assertContains('composeStart', $backup->calls);
    }

    public function test_compose_start_failure_does_not_fail_backup(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();
        $storage = new FakeStorage();
        $project = $this->project($user);
        $record = BackupRecord::prepare($user, $container, 'artisan');
        $backup = new BackupDouble(
            $project,
            $record,
            $storage,
            composeStartThrows: true,
        );

        try {
            $backup->run();
        } catch (\Throwable) {
            $record->refresh();
        }

        $record->refresh();
        $this->assertSame('completed', $record->backupStatus());
        $this->assertNull($record->error);
        $this->assertSame(3, $record->items()->count());
        $this->assertContains('composeStart', $backup->calls);
        $this->assertSame(['put', 'put', 'put'], $storage->calls);
    }

    public function test_optional_storage_prefix_is_applied_to_object_keys(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer(['prefix' => 'backups']);
        $storage = new FakeStorage();
        $backup = $this->createBackup($user, $container, $storage);

        $paths = $backup->model()->items()->orderBy('remote_path')->pluck('remote_path')->all();
        foreach ($paths as $path) {
            $this->assertStringStartsWith('backups/alice/' . $backup->model()->id . '/', $path);
        }
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
