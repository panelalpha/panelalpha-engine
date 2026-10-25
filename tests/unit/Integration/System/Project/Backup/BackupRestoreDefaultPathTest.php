<?php

namespace Tests\Integration\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\Support\JobDeadline;
use App\System\Project\Backup;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectContent;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Backup\ProjectFiles;
use App\System\Project\Backup\ProjectVolumes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The step sequence of a restore into the backup's own project, which is what
 * a backup restore has always been. A restore into a new project is a second
 * mode of the same code; this file pins the first one exactly, so adding the
 * second cannot quietly change it.
 */
class BackupRestoreDefaultPathTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/pa-backup-default-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot.'/users/alice', 0777, true);

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

    public function test_a_container_restore_of_the_files_runs_exactly_these_steps_in_this_order(): void
    {
        $user = $this->makeUser('alice');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = $this->restore($user, $backup, $storage, ['files' => true, 'volumes' => [], 'databases' => []]);

        $this->assertSame([
            'composeStop',
            'extractProjectArchive:project.incoming',
            'swapProject:project.incoming:project.pre-restore',
            'discardProjectAside:project.pre-restore',
            'composeStart',
        ], $restore->calls);
        $this->assertSame('completed', $backup->fresh()->restoreStatus());
    }

    public function test_a_container_restore_of_a_volume_runs_exactly_these_steps_in_this_order(): void
    {
        $user = $this->makeUser('alice');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = $this->restore($user, $backup, $storage, ['files' => false, 'volumes' => ['dbdata'], 'databases' => []]);

        $this->assertSame([
            'composeStop',
            'ensureVolume:proj_dbdata',
            'restoreVolume:proj_dbdata',
            'composeStart',
        ], $restore->calls);
    }

    public function test_a_container_restore_of_a_database_runs_exactly_these_steps_in_this_order(): void
    {
        $user = $this->makeUser('alice');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = $this->restore($user, $backup, $storage, ['files' => false, 'volumes' => [], 'databases' => ['alice_app']]);

        $this->assertSame([
            'composeStop',
            'dumpDatabase',
            'restoreDatabase:alice_app',
            'composeStart',
        ], $restore->calls);
    }

    public function test_a_php_hosting_restore_of_the_files_runs_exactly_these_steps_in_this_order(): void
    {
        $user = $this->makeUser('alice', template: 'default');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = $this->restore($user, $backup, $storage, ['files' => true, 'volumes' => [], 'databases' => []]);

        $this->assertSame([
            'removeHomeEntry:.restore-'.$backup->id,
            'removeEmptyHomeAside:.pre-restore-'.$backup->id,
            'extractHomeArchive:/home/alice/.restore-'.$backup->id,
            'swapHome:.restore-'.$backup->id.':.pre-restore-'.$backup->id,
            'discardHomeAside:.pre-restore-'.$backup->id,
        ], $restore->calls);
    }

    public function test_a_php_hosting_restore_of_a_database_runs_exactly_these_steps_in_this_order(): void
    {
        $user = $this->makeUser('alice', template: 'default');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = $this->restore($user, $backup, $storage, ['files' => false, 'volumes' => [], 'databases' => ['alice_app']]);

        $this->assertSame(['dumpDatabase', 'restoreDatabase:alice_app'], $restore->calls);
    }

    public function test_a_container_restore_loads_the_backed_up_dump_into_the_same_database(): void
    {
        $user = $this->makeUser('alice');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);
        $system = $this->recordingSystem();

        $restore = new ProjectSwapBackup($this->project($user, $system), $backup, $storage);
        $restore->restore(['files' => false, 'volumes' => [], 'databases' => ['alice_app']]);

        $staging = '/var/tmp/panelalpha-backup/alice/'.$backup->id.'/';
        $this->assertSame([
            'composeStop',
            'dumpDatabase:alice_app:'.$staging.'pre-restore-alice_app.sql.gz',
            'restoreDatabase:alice_app:'.$staging.'database-alice_app.sql.gz',
            'composeStart',
        ], $restore->calls);
        $this->assertSame([], $system->commands, 'the dump is loaded as it was backed up, never rewritten');
    }

    public function test_a_php_hosting_restore_loads_the_backed_up_dump_into_the_same_database(): void
    {
        $user = $this->makeUser('alice', template: 'default');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);
        $system = $this->recordingSystem();

        $restore = new ProjectSwapBackup($this->project($user, $system), $backup, $storage);
        $restore->restore(['files' => false, 'volumes' => [], 'databases' => ['alice_app']]);

        $staging = '/var/tmp/panelalpha-backup/alice/'.$backup->id.'/';
        $this->assertSame([
            'dumpDatabase:alice_app:'.$staging.'pre-restore-alice_app.sql.gz',
            'restoreDatabase:alice_app:'.$staging.'database-alice_app.sql.gz',
        ], $restore->calls);
        $this->assertSame([], $system->commands);
    }

    public function test_an_in_place_restore_records_itself_on_the_backup(): void
    {
        $user = $this->makeUser('alice');
        $storage = new FakeStorage();
        $backup = $this->completedBackup($user, $this->makeContainer(), $storage);

        $restore = new BackupDouble($this->project($user, $this->recordingSystem()), $backup, $storage);
        $restore->restore(['files' => true], []);

        $backup->refresh();
        $this->assertSame('completed', $backup->restoreStatus());
        $this->assertSame(
            ['only' => ['files' => true], 'exclude' => [], 'delete_existing_files' => true],
            $backup->restore_details,
        );
    }

    /**
     * @param  array<string, mixed>  $only
     */
    private function restore(User $user, BackupRecord $backup, FakeStorage $storage, array $only): BackupDouble
    {
        $restore = new BackupDouble($this->project($user, $this->recordingSystem()), $backup, $storage);
        $restore->restore($only);

        return $restore;
    }

    private function completedBackup(User $user, BackupContainer $container, FakeStorage $storage): BackupRecord
    {
        $record = BackupRecord::prepare($user, $container, 'artisan');
        (new BackupDouble($this->project($user, $this->recordingSystem()), $record, $storage))->run();

        return $record->fresh(['items']);
    }

    private function project(User $user, System $system): Project
    {
        return new Project($system, $user);
    }

    private function recordingSystem(): RecordingSystem
    {
        return new RecordingSystem($this->tmpRoot);
    }

    private function makeUser(string $username, ?string $template = 'dind'): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username.'.example.test';
        $user->email = $username.'@example.test';
        $user->name = $username;
        $user->status = 'active';
        $user->details = ['template' => $template ?? 'dind', 'mysql_prefix' => $username.'_'];
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

/** Records every host and in-container command the restore runs, in order. */
class RecordingSystem extends System
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
        $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
        $this->commands[] = $argv;

        $process = new Process(['sh', '-c', 'exit 0']);
        $process->run();

        return $process;
    }
}

/** The production restore with the stack stopped and started by hand, the storage replaced, and the volumes and databases recorded. */
class ProjectSwapBackup extends Backup
{
    /** @var list<string> */
    public array $calls = [];

    private ProjectContent $contentDouble;

    private AppStack $stackDouble;

    public function __construct(
        Project $project,
        BackupRecord $record,
        private readonly BackupStorage $storageDouble,
        /** @var list<array{name: string, docker_name: string}> */
        private array $volumes = [['name' => 'dbdata', 'docker_name' => 'proj_dbdata']],
        /** @var list<string> */
        private array $databases = ['alice_app'],
    ) {
        parent::__construct($project, $record);

        $deadline = JobDeadline::startingNow();
        $this->stackDouble = $this->stackFor($project);
        $this->contentDouble = new ProjectContent(
            new ProjectFiles($project, $deadline),
            new HomeFiles($project, $deadline),
            $this->volumesFor($project, $deadline),
            $this->databasesFor($project, $deadline),
        );
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    /** @return list<array{name: string, docker_name: string}> */
    public function namedVolumes(): array
    {
        return $this->volumes;
    }

    /** @return list<string> */
    public function databaseNames(): array
    {
        return $this->databases;
    }

    public static function writeStubFile(string $dest): void
    {
        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dest, 'stub-'.basename($dest));
    }

    protected function resolveStorage(BackupContainer $container): BackupStorage
    {
        return $this->storageDouble;
    }

    protected function content(): ProjectContent
    {
        return $this->contentDouble;
    }

    protected function stack(): AppStack
    {
        return $this->stackDouble;
    }

    private function stackFor(Project $project): AppStack
    {
        return new class ($project, $this) extends AppStack {
            public function __construct(Project $project, private ProjectSwapBackup $double)
            {
                parent::__construct($project);
            }

            public function stop(): void
            {
                $this->double->record('composeStop');
            }

            public function start(): void
            {
                $this->double->record('composeStart');
            }
        };
    }

    private function volumesFor(Project $project, JobDeadline $deadline): ProjectVolumes
    {
        return new class ($project, $deadline, $this) extends ProjectVolumes {
            public function __construct(Project $project, JobDeadline $deadline, private ProjectSwapBackup $double)
            {
                parent::__construct($project, $deadline);
            }

            public function names(): array
            {
                return $this->double->namedVolumes();
            }

            public function archive(string $dockerName, string $dest): void
            {
                ProjectSwapBackup::writeStubFile($dest);
            }

            public function ensure(string $dockerName): void {}

            public function restore(string $dockerName, string $archivePath): void {}

            public function rollback(string $dockerName): void {}
        };
    }

    private function databasesFor(Project $project, JobDeadline $deadline): ProjectDatabases
    {
        return new class ($project, $deadline, $this) extends ProjectDatabases {
            public function __construct(Project $project, JobDeadline $deadline, private ProjectSwapBackup $double)
            {
                parent::__construct($project, $deadline);
            }

            public function names(): array
            {
                return $this->double->databaseNames();
            }

            public function dump(string $name, string $dest): void
            {
                $this->double->record('dumpDatabase:'.$name.':'.$dest);
                ProjectSwapBackup::writeStubFile($dest);
            }

            public function restore(string $name, string $sqlGzPath, ?int $timeout = null): void
            {
                $this->double->record('restoreDatabase:'.$name.':'.$sqlGzPath);
            }

            public function hasRow(string $name): bool
            {
                return true;
            }

            public function size(string $name): int
            {
                return 0;
            }
        };
    }
}
