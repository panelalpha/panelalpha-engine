<?php

namespace Tests\Integration\System;

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
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\SitesDbRequest;
use Tests\TestCase;

class ProjectsPushRollbackTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-push-rollback-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/live', 0777, true);
        mkdir($this->root.'/stg', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
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
        $this->assertFileDoesNotExist($this->rollbackDir().'/live_wp.sql.gz');
    }

    public function test_a_push_and_a_table_push_load_as_the_target_account_chosen_for_the_database(): void
    {
        $cases = [
            'one account' => [['live_wp' => ['live_wp' => 'ALL PRIVILEGES']], ' live_wp'],
            'none: a temporary one' => [[], " ''"],
            'two: the writing one, not the read-only one named like the database' => [['live_wp' => ['live_wp' => 'SELECT'], 'live_a' => ['live_wp' => 'ALL PRIVILEGES']], ' live_a'],
            'two: the writing one, not the read-only one first by name' => [['live_a' => ['live_wp' => 'SELECT'], 'live_b' => ['live_wp' => 'ALL PRIVILEGES']], ' live_b'],
            'two equal: the one named like the database' => [['live_a' => ['live_wp' => 'ALL PRIVILEGES'], 'live_wp' => ['live_wp' => 'ALL PRIVILEGES']], ' live_wp'],
            'two equal: the first by name' => [['live_b' => ['live_wp' => 'SELECT'], 'live_a' => ['live_wp' => 'SELECT']], ' live_a'],
        ];
        $this->stagingPair();
        $live = User::findByUsernameOrFail('live');
        $live->mysqlUsers()->create(['user' => 'live_a']);
        $live->mysqlUsers()->create(['user' => 'live_b']);
        foreach ($cases as $case => [$privileges, $suffix]) {
            foreach ([null, [['database' => 'stg_wp', 'name' => 'wp_posts']]] as $tables) {
                $record = $this->recorder(failStart: true, knownAccounts: false);
                $record->privileges = $privileges;

                $this->pushFails($record, 'start exploded', $tables);

                $this->assertStringEndsWith($suffix, $record->scripts[0], $case);
                $this->assertStringContainsString(' compose.sitesDbCopy -- ', $record->scripts[0], $case);
            }
        }
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

        $this->assertSame([['live_wp', $this->rollbackDir().'/live_wp.sql.gz']], $record->restores);
        $this->assertDirectoryDoesNotExist($this->rollbackDir());
        $this->assertSame('start exploded', User::findByUsernameOrFail('live')->getDetails()['error'] ?? null);
    }

    public function test_a_rollback_drops_a_created_database_and_revokes_the_grants_the_push_gave_on_it(): void
    {
        $record = $this->recorder(failStart: true);
        $record->privileges = ['stg_wp' => ['stg_wp' => 'ALL PRIVILEGES', 'stg_extra' => 'SELECT, INSERT']];
        [$stg] = $this->stagingPair();
        $stg->mysqlDatabases()->create(['database' => 'stg_extra']);

        $this->pushFails($record, 'start exploded');

        $this->assertContains(['live_wp', 'live_extra', 'SELECT, INSERT'], $record->grants);
        $this->assertSame(['live_wp', 'live_extra'], $record->deleted);
        $this->assertSame([['live_wp', 'live_extra']], $record->revoked);
        $this->assertFalse(User::findByUsernameOrFail('live')->mysqlDatabases()->where('database', 'live_extra')->exists());
    }

    public function test_a_failed_restore_keeps_the_rollback_dumps_and_says_so(): void
    {
        $record = $this->recorder(failStart: true, failRestore: true);
        $this->stagingPair();

        $e = $this->pushFails($record, 'start exploded');

        $this->assertFileExists($this->rollbackDir().'/live_wp.sql.gz');
        $this->assertStringContainsString("failed: restoring database 'live_wp'", $e->getMessage());
        $this->assertStringContainsString('kept in the core container at '.$this->rollbackDir(), $e->getMessage());
        $live = User::findByUsernameOrFail('live');
        $this->assertSame('failed', $live->getDetails()['async_status']['push'] ?? null);
        $this->assertSame($e->getMessage(), $live->getDetails()['error'] ?? null);
    }

    public function test_a_failed_table_restore_keeps_the_rollback_dumps_and_says_so(): void
    {
        $record = $this->recorder(failRestore: true, failCopyOf: 'wp_options');
        $this->stagingPair();

        $e = $this->pushFails($record, 'copy exploded', [
            ['database' => 'stg_wp', 'name' => 'wp_posts'],
            ['database' => 'stg_wp', 'name' => 'wp_options'],
        ]);

        $this->assertFileExists($this->rollbackDir().'/live_wp.wp_posts.sql.gz');
        $this->assertStringContainsString("restoring table 'live_wp.wp_posts'", $e->getMessage());
        $this->assertStringContainsString('kept in the core container at '.$this->rollbackDir(), $e->getMessage());
    }

    public function test_kept_rollback_dumps_refuse_the_next_push_and_stay(): void
    {
        $record = $this->recorder();
        $this->stagingPair();
        mkdir($this->rollbackDir(), 0777, true);
        file_put_contents($this->rollbackDir().'/live_wp.sql.gz', 'only copy');

        $this->pushFails($record, 'restore or remove them before pushing again');

        $this->assertSame('only copy', file_get_contents($this->rollbackDir().'/live_wp.sql.gz'));
        $this->assertSame([], $record->scripts);
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
     * @param  list<array{database: string, name: string}>|null  $tables
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
        return $this->root.'/rollbacks/live';
    }

    private function recorder(
        ?string $failDumpOf = null,
        bool $failStart = false,
        bool $failRestore = false,
        ?string $failCopyOf = null,
        bool $knownAccounts = true,
    ): object {
        return (object) [
            'knownAccounts' => $knownAccounts,
            'failDumpOf' => $failDumpOf,
            'failStart' => $failStart,
            'failRestore' => $failRestore,
            'failCopyOf' => $failCopyOf,
            'restores' => [],
            'deleted' => [],
            'scripts' => [],
            'privileges' => [],
            'grants' => [],
            'revoked' => [],
        ];
    }

    private function system(object $record): System
    {
        return new class($record, $this->root) extends System
        {
            public function __construct(private object $record, private string $root) {}

            public function pushRollbacksDirPath(): string
            {
                return $this->root.'/rollbacks';
            }

            public function project(User $model): Project
            {
                return new class($this->root.'/'.$model->username, $this->record) extends Project
                {
                    public function __construct(private string $home, private object $record) {}

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

                return new PushRollbackProcess($this->exitCode(LocalRoot::isRequest($argv) ? LocalRoot::plain($argv) : $argv));
            }

            /**
             * @param  list<string>  $argv
             */
            private function exitCode(array $argv): int
            {
                $path = end($argv);

                return match (array_slice($argv, 0, 2)) {
                    ['test', '-d'] => is_dir($path) ? 0 : 1,
                    ['test', '-e'] => file_exists($path) ? 0 : 1,
                    ['test', '-L'] => is_link($path) ? 0 : 1,
                    ['mkdir', '-p'] => (is_dir($path) || mkdir($path, 0777, true)) ? 0 : 1,
                    ['rm', '-rf'] => $this->remove(array_slice($argv, 2)),
                    default => 0,
                };
            }

            /**
             * @param  list<string>  $paths
             */
            private function remove(array $paths): int
            {
                foreach ($paths as $path) {
                    exec('rm -rf '.escapeshellarg($path));
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

    public function importAccount(User $owner, string $database): ImportAccount
    {
        return $this->record->knownAccounts ? new ImportAccount($database) : parent::importAccount($owner, $database);
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

    public function copyInSitesDb(string $from, string $to, ?string $table, ImportAccount $account, string $failurePrefix): void
    {
        $this->recordScript(SitesDbRequest::copy($from, $to, $table, $account), $failurePrefix);
    }

    public function dropTableInSitesDb(string $database, string $table): void
    {
        $this->recordScript(SitesDbRequest::drop($database, $table), 'table drop failed');
    }

    private function recordScript(string $script, string $failurePrefix): void
    {
        if ($this->record->failCopyOf !== null && str_contains($script, ' '.$this->record->failCopyOf)) {
            throw new \RuntimeException('copy exploded');
        }

        $this->record->scripts[] = $script;
    }

    public function databases(): Databases
    {
        $record = $this->record;

        return new class($record) extends Databases
        {
            public function __construct(private object $record)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function createDatabase(string $name): void {}

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
        $record = $this->record;

        return new class($record) extends Privileges
        {
            public function __construct(private object $record)
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function showPrivileges(string $user): array
            {
                return $this->record->privileges[$user] ?? [];
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
                $this->record->grants[] = [$user, $database, $privileges];
            }

            public function revokeIfExists(string $user, string $database): void
            {
                $this->record->revoked[] = [$user, $database];
            }
        };
    }

    public function tables(): Tables
    {
        return new class extends Tables
        {
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
