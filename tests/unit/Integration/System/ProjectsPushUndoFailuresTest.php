<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\CopyVolumes;
use App\System\Projects;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Tables;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\KnowsEveryImportAccount;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** A database push whose undo cannot finish names each step it could not undo; a stale `.incoming` is cleared first. */
class ProjectsPushUndoFailuresTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private object $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-push-undo-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/live', 0777, true);
        mkdir($this->root . '/stg', 0777, true);
        $this->record = (object) ['failStart' => false, 'failDropOf' => null, 'restores' => [], 'deleted' => []];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_stale_incoming_directory_is_removed_before_the_push(): void
    {
        mkdir($this->root . '/live/.incoming/leftover', 0777, true);
        $this->stagingPair();

        $this->push();

        $this->assertDirectoryDoesNotExist($this->root . '/live/.incoming');
        $this->assertSame('completed', User::findByUsernameOrFail('live')->asyncStatus()['push'] ?? null);
    }

    public function test_deploy_settings_that_cannot_be_restored_are_named(): void
    {
        $this->record->failStart = true;
        $this->stagingPair();
        $refusals = 0;
        User::saving(function (User $user) use (&$refusals): void {
            if ($user->username === 'live' && $this->record->restores !== [] && $refusals++ === 0) {
                throw new \RuntimeException('sites-db went away');
            }
        });

        $e = $this->pushFails();

        $this->assertStringContainsString('start exploded', $e->getMessage());
        $this->assertStringContainsString('restoring the deploy settings', $e->getMessage());
        $this->assertSame($e->getMessage(), User::findByUsernameOrFail('live')->getDetails()['error'] ?? null);
    }

    public function test_a_created_database_that_cannot_be_dropped_is_named_and_its_row_kept(): void
    {
        $this->record->failStart = true;
        $this->record->failDropOf = 'live_extra';
        [$stg] = $this->stagingPair();
        $stg->mysqlDatabases()->create(['database' => 'stg_extra']);

        $e = $this->pushFails();

        $this->assertStringContainsString("dropping created database 'live_extra'", $e->getMessage());
        $this->assertTrue(User::findByUsernameOrFail('live')->mysqlDatabases()->where('database', 'live_extra')->exists());
    }

    /** @return array{0: User, 1: User} */
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

    private function push(): void
    {
        (new Projects($this->system()))->pushToApp('stg', 'live', files: false);
    }

    private function pushFails(): \RuntimeException
    {
        try {
            $this->push();
        } catch (\RuntimeException $e) {
            return $e;
        }

        $this->fail('the push should have failed');
    }

    private function system(): System
    {
        return new class ($this->record, $this->root) extends System {
            public function __construct(private object $record, private string $root)
            {
            }

            public function pushRollbacksDirPath(): string
            {
                return $this->root . '/rollbacks';
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
                return new PushUndoMysql($this->record);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $plain = LocalRoot::isRequest($argv) ? LocalRoot::plain($argv) : $argv;
                $path = (string) end($plain);
                if (array_slice($plain, 0, 2) === ['rm', '-rf']) {
                    foreach (array_slice($plain, 2) as $target) {
                        exec('rm -rf ' . escapeshellarg($target));
                    }
                }

                return FakeProcess::of(match (array_slice($plain, 0, 2)) {
                    ['test', '-d'] => is_dir($path) ? 0 : 1,
                    ['test', '-e'] => file_exists($path) ? 0 : 1,
                    ['test', '-L'] => is_link($path) ? 0 : 1,
                    default => 0,
                });
            }
        };
    }
}

/** @internal Dumps to files, restores by recording, and drops databases except the one it is told to fail on. */
final class PushUndoMysql extends Mysql
{
    use KnowsEveryImportAccount;

    public function __construct(private object $record)
    {
        parent::__construct(null);
    }

    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
    {
        file_put_contents($dest, 'dump');
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        $this->record->restores[] = $name;
    }

    public function copyInSitesDb(string $from, string $to, ?string $table, ImportAccount $account, string $failurePrefix): void
    {
    }

    public function dropTableInSitesDb(string $database, string $table): void
    {
    }

    public function databases(): Databases
    {
        return new class ($this->record) extends Databases {
            public function __construct(private object $record)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function createDatabase(string $name): void
            {
            }

            public function deleteDatabase(string $name): void
            {
                if ($name === $this->record->failDropOf) {
                    throw new \RuntimeException("cannot drop {$name}: it is in use");
                }
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
        return new class () extends Privileges {
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

            public function revokeIfExists(string $user, string $database): void
            {
            }
        };
    }

    public function tables(): Tables
    {
        return new class () extends Tables {
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
