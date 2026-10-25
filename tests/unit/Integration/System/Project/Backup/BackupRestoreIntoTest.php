<?php

namespace Tests\Integration\System\Project\Backup;

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

/**
 * A backup restored into a second project: the same restore protocol, aimed at
 * another account, with the databases taking that account's prefix and the
 * project that owns the backup left alone.
 */
class BackupRestoreIntoTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/pa-backup-copy-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot.'/users', 0777, true);

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
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
            $table->string('kind')->default('manual');
            $table->string('note')->nullable();
            $table->json('contents')->nullable();
            $table->json('async_status')->nullable();
            $table->json('restore_details')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('mysql_databases', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('database');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('mysql_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('user');
            $table->json('details')->nullable();
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

    public function test_a_copy_restores_the_databases_under_the_new_projects_prefix(): void
    {
        $source = $this->makeUser('alice');
        $target = $this->makeUser('bob');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($source, $storage);
        $system = $this->recordingSystem();

        $copy = $this->copy($source, $target, $backup, $storage, $system);
        $copy->restoreInto($this->project($target, $system));

        $this->assertEqualsCanonicalizing([
            'extractProjectArchive:project.incoming',
            'swapProject:project.incoming:project.pre-restore',
            'ensureVolume:proj_dbdata',
            'restoreVolume:proj_dbdata',
            'dumpDatabase',
            'restoreDatabase:bob_app',
            'discardProjectAside:project.pre-restore',
        ], $copy->calls);
        $this->assertArrayNotHasKey('alice_app', $copy->restoredDumps);
        $this->assertSame('running', $target->fresh()->asyncStatus()['restore'], 'a copy is completed only once it is started');
    }

    public function test_a_copy_loads_the_source_dump_rewritten_for_the_new_project(): void
    {
        $source = $this->makeUser('alice');
        $target = $this->makeUser('bob');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($source, $storage);
        $system = $this->recordingSystem();

        $copy = $this->copy($source, $target, $backup, $storage, $system);
        $copy->restoreInto($this->project($target, $system));

        $this->assertStringEndsWith('/database-alice_app.sql.gz', $copy->restoredDumps['bob_app']);
        $this->assertSame(
            ['from' => 'alice_app', 'orphan' => 'bob_no.definer', 'accounts' => []],
            $copy->copiedDatabases['bob_app'],
            'the schema name and every DEFINER are rewritten from the source project to the new one',
        );
    }

    public function test_a_copy_leaves_the_backup_it_was_made_from_alone(): void
    {
        $source = $this->makeUser('alice');
        $target = $this->makeUser('bob');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($source, $storage);
        $before = $backup->fresh()->async_status;

        $copy = $this->copy($source, $target, $backup, $storage, $this->recordingSystem());
        $copy->restoreInto($this->project($target, $this->recordingSystem()));

        $backup->refresh();
        $this->assertNull($backup->restore_details);
        $this->assertSame($before, $backup->async_status);
        $this->assertNull($backup->error);
    }

    public function test_a_copy_stops_and_starts_nothing(): void
    {
        $source = $this->makeUser('alice');
        $target = $this->makeUser('bob');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($source, $storage);
        $system = $this->recordingSystem();

        $copy = $this->copy($source, $target, $backup, $storage, $system);
        $copy->restoreInto($this->project($target, $system));

        $this->assertNotContains('composeStop', $copy->calls);
        $this->assertNotContains('composeStart', $copy->calls);
    }

    public function test_a_failed_copy_records_the_failure_on_the_new_project(): void
    {
        $source = $this->makeUser('alice');
        $target = $this->makeUser('bob');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($source, $storage);
        $system = $this->recordingSystem();

        $copy = $this->copy($source, $target, $backup, $storage, $system, restoreVolumeThrows: true);
        $copy->restoreInto($this->project($target, $system));

        $target->refresh();
        $this->assertSame('failed', $target->asyncStatus()['restore']);
        $this->assertStringStartsWith('Restore failed: ', (string) $target->getDetails()['error']);
        $this->assertNull($backup->fresh()->restore_details);
        $this->assertNull($backup->fresh()->error);
    }

    private function copy(
        User $source,
        User $target,
        BackupRecord $backup,
        FakeStorage $storage,
        System $system,
        bool $restoreVolumeThrows = false,
    ): BackupDouble {
        return new BackupDouble(
            $this->project($source, $system),
            $backup,
            $storage,
            restoreVolumeThrows: $restoreVolumeThrows,
        );
    }

    private function completedBackup(User $source, FakeStorage $storage): BackupRecord
    {
        $record = BackupRecord::prepare($source, $this->makeContainer(), 'artisan');
        (new BackupDouble($this->project($source, $this->recordingSystem()), $record, $storage))->run();

        return $record->fresh(['items']);
    }

    private function project(User $user, System $system): Project
    {
        return new Project($system, $user);
    }

    private function recordingSystem(): CopyRecordingSystem
    {
        return new CopyRecordingSystem($this->tmpRoot);
    }

    private function makeUser(string $username): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username.'.example.test';
        $user->email = $username.'@example.test';
        $user->name = $username;
        $user->status = 'active';
        $user->details = [
            'template' => 'dind',
            'mysql_prefix' => $username.'_',
            'UID' => 1000,
            'GID' => 1000,
        ];
        $user->save();

        return $user;
    }

    private function makeContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'test-'.uniqid('', true);
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = null;
        $container->save();

        return $container;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
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

/** Records the commands a restore runs, host and engine alike. */
class CopyRecordingSystem extends System
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private string $engineRoot) {}

    public function engineDirPath(): string
    {
        return $this->engineRoot;
    }

    public function filesystem(): Filesystem
    {
        return new class ($this) extends Filesystem {
            public function assertFreeSpace(int $bytes): void {}
        };
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = is_array($cmd) ? array_values($cmd) : [$cmd];

        return '';
    }

    public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = is_array($cmd) ? array_values($cmd) : [$cmd];

        return '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = is_array($cmd) ? array_values($cmd) : [$cmd];

        $process = new Process(['sh', '-c', 'exit 1']);
        $process->run();

        return $process;
    }
}
