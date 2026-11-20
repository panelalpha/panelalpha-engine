<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\MysqlDatabase;
use App\Models\User;
use App\Support\JobDeadline;
use App\System;
use App\System\Project;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOStatement;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A backup's databases: the account's rows name them, the shared MariaDB dumps, restores and recreates them.
 */
class ProjectDatabasesTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<array{0: string, 1: string, 2: string, 3: int}> */
    private array $calls = [];

    /** @var list<string> */
    private array $serverDatabases = [];

    /** @var list<string> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_names_are_the_accounts_database_rows(): void
    {
        $user = $this->accountWith(['alice_shop', 'alice_blog']);

        $this->assertSame(['alice_shop', 'alice_blog'], $this->databases($user)->names());
    }

    public function test_an_account_without_rows_has_no_databases(): void
    {
        $this->assertSame([], $this->databases($this->accountWith([]))->names());
    }

    public function test_a_dump_and_a_restore_run_on_the_steps_window(): void
    {
        $databases = $this->databases($this->accountWith(['alice_shop']));

        $databases->dump('alice_shop', '/tmp/alice_shop.sql.gz');
        $databases->restore('alice_shop', '/tmp/alice_shop.sql.gz');

        $this->assertSame(['dump', 'alice_shop', '/tmp/alice_shop.sql.gz'], array_slice($this->calls[0], 0, 3));
        $this->assertSame(['restore', 'alice_shop', '/tmp/alice_shop.sql.gz'], array_slice($this->calls[1], 0, 3));
        $this->assertGreaterThan(1, $this->calls[0][3]);
        $this->assertGreaterThan(1, $this->calls[1][3]);
    }

    public function test_a_restore_given_its_own_timeout_runs_on_it(): void
    {
        $databases = $this->databases($this->accountWith(['alice_shop']));

        $databases->restore('alice_shop', '/tmp/alice_shop.sql.gz', 45);

        $this->assertSame([['restore', 'alice_shop', '/tmp/alice_shop.sql.gz', 45]], $this->calls);
    }

    public function test_size_and_existence_are_read_from_the_server(): void
    {
        $this->serverDatabases = ['alice_shop'];
        $databases = $this->databases($this->accountWith(['alice_shop']));

        $this->assertSame(4096, $databases->size('alice_shop'));
        $this->assertTrue($databases->exists('alice_shop'));
        $this->assertFalse($databases->exists('alice_gone'));
    }

    public function test_a_row_is_known_only_for_the_accounts_own_databases(): void
    {
        $databases = $this->databases($this->accountWith(['alice_shop']));

        $this->assertTrue($databases->hasRow('alice_shop'));
        $this->assertFalse($databases->hasRow('alice_blog'));
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        Log::spy();
        $user = $this->accountWith(['alice_shop']);

        $this->databases($user)->ensureRow('alice_shop');

        $this->assertSame(1, MysqlDatabase::where('user_id', $user->id)->count());
        $this->assertSame([], $this->executed);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_dropped_database_is_recreated_with_its_row(): void
    {
        Log::spy();
        $user = $this->accountWith([]);

        $this->databases($user)->ensureRow('alice_shop');

        $this->assertSame(['CREATE DATABASE `alice_shop`'], $this->executed);
        $this->assertSame(['alice_shop'], MysqlDatabase::where('user_id', $user->id)->pluck('database')->all());
        Log::shouldHaveReceived('warning')->once()->with('Restoring a database whose row no longer exists', [
            'username' => 'alice',
            'database' => 'alice_shop',
        ]);
    }

    public function test_a_database_still_on_the_server_only_gets_its_row_back(): void
    {
        Log::spy();
        $this->serverDatabases = ['alice_shop'];
        $user = $this->accountWith([]);

        $this->databases($user)->ensureRow('alice_shop');

        $this->assertSame([], $this->executed);
        $this->assertSame(['alice_shop'], MysqlDatabase::where('user_id', $user->id)->pluck('database')->all());
    }

    /** @param  list<string>  $names */
    private function accountWith(array $names): User
    {
        $user = $this->makeUser('alice');
        foreach ($names as $name) {
            MysqlDatabase::create(['user_id' => $user->id, 'database' => $name]);
        }

        return $user->fresh() ?? $user;
    }

    private function databases(User $user): ProjectDatabases
    {
        return new ProjectDatabases(new Project($this->system(), $user), JobDeadline::startingNow());
    }

    private function system(): System
    {
        return new class ($this->recordingMysql()) extends System {
            public function __construct(private Mysql $recording) {}

            public function mysql(): Mysql
            {
                return $this->recording;
            }
        };
    }

    private function recordingMysql(): Mysql
    {
        $test = $this;

        return new class ($test) extends Mysql {
            private int $timeout = 0;

            public function __construct(private ProjectDatabasesTest $test)
            {
                parent::__construct();
            }

            public function withTimeout(int $timeout): Mysql
            {
                $clone = clone $this;
                $clone->timeout = $timeout;

                return $clone;
            }

            public function dumpToGzip(string $name, string $dest, ?string $table = null): void
            {
                $this->test->record(['dump', $name, $dest, $this->timeout]);
            }

            public function restoreFromGzip(string $name, string $sqlGzPath): void
            {
                $this->test->record(['restore', $name, $sqlGzPath, $this->timeout]);
            }

            public function databases(): Databases
            {
                return new Databases($this->test->server());
            }
        };
    }

    /** @param  array{0: string, 1: string, 2: string, 3: int}  $call */
    public function record(array $call): void
    {
        $this->calls[] = $call;
    }

    /** A SQLite stand-in for the shared MariaDB: it lists $serverDatabases, sizes each at 4 KiB and records exec. */
    public function server(): PDO
    {
        $pdo = new class ($this->executed) extends PDO {
            /** @param  list<string>  $executed */
            public function __construct(private array &$executed)
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                parent::exec('CREATE TABLE schemata (name TEXT)');
                parent::exec("ATTACH DATABASE ':memory:' AS information_schema");
                parent::exec('CREATE TABLE information_schema.tables (table_schema TEXT, data_length INTEGER, index_length INTEGER)');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return parent::prepare($query === 'SHOW DATABASES LIKE ?' ? 'SELECT name FROM schemata WHERE name LIKE ?' : $query, $options);
            }

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return 0;
            }

            public function seed(string $name): void
            {
                parent::prepare('INSERT INTO schemata VALUES (?)')->execute([$name]);
                parent::prepare('INSERT INTO information_schema.tables VALUES (?, 3072, 1024)')->execute([$name]);
            }
        };
        foreach ($this->serverDatabases as $name) {
            $pdo->seed($name);
        }

        return $pdo;
    }
}
