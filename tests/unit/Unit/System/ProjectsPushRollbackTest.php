<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Projects;
use App\System\Project\Dind\CopyVolumes;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Tables;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class ProjectsPushRollbackTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-push-rollback-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/live', 0777, true);
        mkdir($this->root . '/stg', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_failed_rollback_dump_is_never_replayed_into_the_target(): void
    {
        $record = $this->recorder(failDumpOf: 'live_wp');
        $this->stagingPair();

        $this->pushFails($record, 'mysqldump failed: disk full');

        $this->assertSame([], $record->restores);
        $this->assertSame([], $record->deleted);
        $this->assertSame([], $record->scripts);
        $this->assertFileDoesNotExist($this->rollbackDir() . '/live_wp.sql.gz');
    }

    public function test_a_failed_table_rollback_dump_is_never_replayed_into_the_target(): void
    {
        $record = $this->recorder(failDumpOf: 'live_wp');
        $this->stagingPair();

        $this->pushFails($record, 'mysqldump failed: disk full', [['database' => 'stg_wp', 'name' => 'wp_posts']]);

        $this->assertSame([], $record->restores);
        $this->assertSame([], $record->scripts);
    }

    public function test_a_later_failure_restores_every_dumped_database(): void
    {
        $record = $this->recorder(failStart: true);
        $this->stagingPair();

        $this->pushFails($record, 'start exploded');

        $this->assertSame([['live_wp', $this->rollbackDir() . '/live_wp.sql.gz']], $record->restores);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function stagingPair(): array
    {
        $live = $this->makeUser('live', ['mysql_prefix' => 'live_']);
        $live->mysqlDatabases()->create(['database' => 'live_wp']);
        $live->mysqlUsers()->create(['user' => 'live_wp']);
        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_']);
        $stg->mysqlDatabases()->create(['database' => 'stg_wp']);
        $stg->mysqlUsers()->create(['user' => 'stg_wp']);
        $stg->staging = $live->id;
        $stg->save();

        return [$stg->refresh(), $live->refresh()];
    }

    /**
     * @param list<array{database: string, name: string}>|null $tables
     */
    private function pushFails(object $record, string $message, ?array $tables = null): \RuntimeException
    {
        try {
            (new Projects($this->system($record)))->pushToApp('stg', 'live', files: false, tables: $tables);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return $e;
        }

        $this->fail('the push should have failed');
    }

    private function rollbackDir(): string
    {
        return $this->root . '/live/.pre-push-db';
    }

    private function recorder(
        ?string $failDumpOf = null,
        bool $failStart = false,
        bool $failRestore = false,
        ?string $failCopyOf = null,
    ): object {
        return (object) [
            'failDumpOf'  => $failDumpOf,
            'failStart'   => $failStart,
            'failRestore' => $failRestore,
            'failCopyOf'  => $failCopyOf,
            'restores'    => [],
            'deleted'     => [],
            'scripts'     => [],
        ];
    }

    private function system(object $record): System
    {
        return new class ($record, $this->root) extends System {
            public function __construct(private object $record, private string $root)
            {
            }

            public function project(User $model): Project
            {
                return new class ($this->root . '/' . $model->username, $this->record) extends Project {
                    public function __construct(private string $home, private object $record)
                    {
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void
                    {
                        if ($this->record->failStart) {
                            throw new \RuntimeException('start exploded');
                        }
                    }
                };
            }

            public function mysql(): Mysql
            {
                return new PushRollbackMysql($this->record);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];

                return new PushRollbackProcess($this->exitCode($argv));
            }

            /**
             * @param list<string> $argv
             */
            private function exitCode(array $argv): int
            {
                $path = end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => is_dir($path) ? 0 : 1,
                    ['sudo', 'test', '-e'] => file_exists($path) ? 0 : 1,
                    ['sudo', 'test', '-L'] => is_link($path) ? 0 : 1,
                    ['sudo', 'mkdir', '-p'] => (is_dir($path) || mkdir($path, 0777, true)) ? 0 : 1,
                    ['sudo', 'rm', '-rf'] => $this->remove(array_slice($argv, 3)),
                    default => 0,
                };
            }

            /**
             * @param list<string> $paths
             */
            private function remove(array $paths): int
            {
                foreach ($paths as $path) {
                    exec('rm -rf ' . escapeshellarg($path));
                }

                return 0;
            }
        };
    }
}

class PushRollbackProcess extends Process
{
    public function __construct(private int $code)
    {
        parent::__construct(['true']);
    }

    public function isSuccessful(): bool
    {
        return $this->code === 0;
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function getOutput(): string
    {
        return '';
    }
}

class PushRollbackMysql extends Mysql
{
    public function __construct(private object $record)
    {
        parent::__construct(null);
    }

    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
    {
        if ($name === $this->record->failDumpOf) {
            throw new \RuntimeException('mysqldump failed: disk full');
        }

        file_put_contents($dest, 'dump');
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        if ($this->record->failRestore) {
            throw new \RuntimeException('database restore failed: sites-db is restarting');
        }

        $this->record->restores[] = [$name, $sqlGzPath];
    }

    public function runSitesDbScript(string $script, string $failurePrefix): void
    {
        if ($this->record->failCopyOf !== null && str_contains($script, "'" . $this->record->failCopyOf . "'")) {
            throw new \RuntimeException('copy exploded');
        }

        $this->record->scripts[] = $script;
    }

    public function databases(): Databases
    {
        $record = $this->record;

        return new class ($record) extends Databases {
            public function __construct(private object $record)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function createDatabase(string $name): void
            {
            }

            public function deleteDatabase(string $name): void
            {
                $this->record->deleted[] = $name;
            }

            public function databaseExists(string $name): bool
            {
                return true;
            }
        };
    }

    public function privileges(): Privileges
    {
        return new class extends Privileges {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function showPrivileges(string $user): array
            {
                return [];
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
            }
        };
    }

    public function tables(): Tables
    {
        return new class extends Tables {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function exists(string $database, string $table): bool
            {
                return true;
            }
        };
    }
}
