<?php

namespace Tests\Integration\System;

use App\Exceptions\RollbackIncompleteException;
use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\AccountMap;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\TableReplacement;
use App\System\Services\Mysql\Tables;
use Illuminate\Support\Facades\Log;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\Support\KnowsEveryImportAccount;
use Tests\Support\SitesDbRequest;
use Tests\TestCase;

/** What a table rollback undoes, in which order, and what it reports when an undo fails too. */
class DatabaseCopierRestoreFailuresTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_every_undo_runs_in_order_and_each_failure_is_reported(): void
    {
        Log::spy();
        $journal = new \ArrayObject;
        $copier = new DatabaseCopier($this->mysql($journal, failRestores: ['/r/b_one.t1.sql.gz'], failScripts: [' t3'], failDeletes: ['b_two']));

        $failures = $copier->restoreTables(new TableReplacement(
            new DatabaseCopyResult,
            AccountMap::map('a_', 'b_', ['a_u1', 'a_u2'], ['b_u1', 'b_u2']),
            [
                ['database' => 'b_one', 'table' => 't1', 'dump' => '/r/b_one.t1.sql.gz'],
                ['database' => 'b_one', 'table' => 't2', 'dump' => '/r/b_one.t2.sql.gz'],
            ],
            ['b_two', 'b_three'],
            [
                ['database' => 'b_two', 'table' => 't3'],
                ['database' => 'b_three', 'table' => 't4'],
            ],
        ));

        $this->assertSame([
            "restoring table 'b_one.t1'",
            "dropping created table 'b_two.t3'",
            "dropping created database 'b_two'",
        ], $failures);
        $this->assertSame([
            'restore b_one /r/b_one.t1.sql.gz',
            'restore b_one /r/b_one.t2.sql.gz',
            'script /usr/local/lib/panelalpha/pa-root compose.sitesDbDropTable -- /opt/panelalpha/shared-hosting b_two t3',
            'script /usr/local/lib/panelalpha/pa-root compose.sitesDbDropTable -- /opt/panelalpha/shared-hosting b_three t4',
            'exists b_two',
            'delete b_two',
            'exists b_three',
            'delete b_three',
            'revoke b_u1 b_three',
            'revoke b_u2 b_three',
        ], $journal->getArrayCopy());
        Log::shouldHaveReceived('warning')->with("Push rollback failed for table 'b_one.t1': restore of /r/b_one.t1.sql.gz failed")->once();
        Log::shouldHaveReceived('warning')->with("Push cleanup failed for table 'b_two.t3': script failed")->once();
        Log::shouldHaveReceived('warning')->with("Push cleanup failed for database 'b_two': delete of b_two failed")->once();
    }

    public function test_nothing_to_undo_reports_nothing(): void
    {
        $journal = new \ArrayObject;

        $failures = (new DatabaseCopier($this->mysql($journal)))->restoreTables(
            new TableReplacement(new DatabaseCopyResult, AccountMap::map('a_', 'b_', [], []))
        );

        $this->assertSame([], $failures);
        $this->assertSame([], $journal->getArrayCopy());
    }

    public function test_a_replace_whose_rollback_fails_names_what_it_left(): void
    {
        Log::spy();
        $journal = new \ArrayObject;
        $copier = new DatabaseCopier($this->mysql(
            $journal,
            tables: ['bob_wp' => ['wp_posts', 'wp_options']],
            failRestores: ['/tmp/g23-rollback/bob_wp.wp_posts.sql.gz'],
            failScripts: [' wp_options'],
        ));

        try {
            $copier->replaceTables(
                $this->userWithDatabase('alice', 'alice_wp'),
                $this->userWithDatabase('bob', 'bob_wp'),
                [
                    ['database' => 'alice_wp', 'name' => 'wp_posts'],
                    ['database' => 'alice_wp', 'name' => 'wp_options'],
                ],
                '/tmp/g23-rollback',
            );
            $this->fail('the second table copy should have failed');
        } catch (RollbackIncompleteException $e) {
            $this->assertSame('script failed', $e->getMessage());
            $this->assertSame(["restoring table 'bob_wp.wp_posts'"], $e->failures);
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('script failed', $e->getPrevious()->getMessage());
        }

        $this->assertSame([
            'dump bob_wp wp_posts /tmp/g23-rollback/bob_wp.wp_posts.sql.gz',
            'script /usr/local/lib/panelalpha/pa-root compose.sitesDbCopy -- /opt/panelalpha/shared-hosting alice_wp bob_wp wp_posts bob_wp',
            'dump bob_wp wp_options /tmp/g23-rollback/bob_wp.wp_options.sql.gz',
            'script /usr/local/lib/panelalpha/pa-root compose.sitesDbCopy -- /opt/panelalpha/shared-hosting alice_wp bob_wp wp_options bob_wp',
            'restore bob_wp /tmp/g23-rollback/bob_wp.wp_posts.sql.gz',
            'restore bob_wp /tmp/g23-rollback/bob_wp.wp_options.sql.gz',
        ], $journal->getArrayCopy());
    }

    public function test_a_replace_whose_rollback_succeeds_rethrows_the_failure_itself(): void
    {
        $journal = new \ArrayObject;
        $copier = new DatabaseCopier($this->mysql($journal, tables: ['bob_wp' => ['wp_posts']], failScripts: [' wp_posts']));

        try {
            $copier->replaceTables(
                $this->userWithDatabase('alice', 'alice_wp'),
                $this->userWithDatabase('bob', 'bob_wp'),
                [['database' => 'alice_wp', 'name' => 'wp_posts']],
                '/tmp/g23-rollback',
            );
            $this->fail('the table copy should have failed');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(RollbackIncompleteException::class, $e);
            $this->assertSame('script failed', $e->getMessage());
        }
    }

    private function userWithDatabase(string $name, string $database): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name . '_']);
        $user->mysqlDatabases()->create(['database' => $database]);

        return $user->refresh();
    }

    /**
     * @param  array<string, list<string>>  $tables
     * @param  list<string>  $failRestores
     * @param  list<string>  $failScripts
     * @param  list<string>  $failDeletes
     */
    private function mysql(
        \ArrayObject $journal,
        array $tables = [],
        array $failRestores = [],
        array $failScripts = [],
        array $failDeletes = [],
    ): Mysql {
        return new class ($journal, $tables, $failRestores, $failScripts, $failDeletes) extends Mysql {
            use KnowsEveryImportAccount;

            public function __construct(
                private \ArrayObject $journal,
                private array $tableNames,
                private array $failRestores,
                private array $failScripts,
                private array $failDeletes,
            ) {
                parent::__construct(null);
            }

            public function tables(): Tables
            {
                return new class ($this->tableNames) extends Tables {
                    public function __construct(private array $names)
                    {
                        parent::__construct(new PDO('sqlite::memory:'));
                    }

                    public function exists(string $database, string $table): bool
                    {
                        return in_array($table, $this->names[$database] ?? [], true);
                    }
                };
            }

            public function dumpToGzip(string $name, string $dest, ?string $table = null): void
            {
                $this->journal[] = "dump {$name} {$table} {$dest}";
            }

            public function restoreFromGzip(string $name, string $sqlGzPath): void
            {
                $this->journal[] = "restore {$name} {$sqlGzPath}";
                if (in_array($sqlGzPath, $this->failRestores, true)) {
                    throw new \RuntimeException("restore of {$sqlGzPath} failed");
                }
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
                $this->journal[] = "script {$script}";
                foreach ($this->failScripts as $needle) {
                    if (str_contains($script, $needle)) {
                        throw new \RuntimeException('script failed');
                    }
                }
            }

            public function privileges(): Privileges
            {
                return new class ($this->journal) extends Privileges {
                    public function __construct(private \ArrayObject $journal)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        return [];
                    }

                    public function revokeIfExists(string $user, string $database): void
                    {
                        $this->journal[] = "revoke {$user} {$database}";
                    }
                };
            }

            public function databases(): Databases
            {
                return new class ($this->journal, $this->failDeletes) extends Databases {
                    public function __construct(private \ArrayObject $journal, private array $failDeletes)
                    {
                        parent::__construct(new PDO('sqlite::memory:'));
                    }

                    public function databaseExists(string $name): bool
                    {
                        $this->journal[] = "exists {$name}";

                        return true;
                    }

                    public function deleteDatabase(string $name): void
                    {
                        $this->journal[] = "delete {$name}";
                        if (in_array($name, $this->failDeletes, true)) {
                            throw new \RuntimeException("delete of {$name} failed");
                        }
                    }
                };
            }
        };
    }
}
