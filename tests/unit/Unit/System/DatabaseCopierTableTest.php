<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\DefinerMap;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\TableReplacement;
use App\System\Services\Mysql\Tables;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DatabaseCopierTableTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_copy_table_streams_the_named_table_through_the_definer_rewrite(): void
    {
        $record = $this->recorder();

        $this->copier($record)->copyTable('alice_wp', 'bob_wp', 'wp_posts', DefinerMap::map('alice_', 'bob_', [], []));

        $this->assertCount(1, $record->scripts);
        $this->assertStringContainsString(
            "mariadb-dump --single-transaction -uroot 'alice_wp' 'wp_posts'",
            $record->scripts[0]
        );
        $this->assertStringNotContainsString('--routines', $record->scripts[0]);
        $this->assertStringContainsString('DEFINER=`bob_no.definer`@`%`', $record->scripts[0]);
        $this->assertStringContainsString("'bob_wp'", $record->scripts[0]);
    }

    public function test_a_table_name_outside_the_grammar_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table name');

        $this->copier($this->recorder())->copyTable(
            'alice_wp',
            'bob_wp',
            'wp_posts`; DROP TABLE x',
            DefinerMap::map('alice_', 'bob_', [], []),
        );
    }

    public function test_drop_table_quotes_the_identifier(): void
    {
        $record = $this->recorder();

        $this->copier($record)->dropTable('bob_wp', 'wp_$posts');

        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_$posts`', $record->scripts[0]);
    }

    public function test_replace_tables_dumps_the_target_table_before_it_is_touched(): void
    {
        $record = $this->recorder();
        $replacement = $this->copier($record, ['bob_wp' => ['wp_posts']])->replaceTables(
            $this->userWithDatabase('alice', 'alice_wp'),
            $this->userWithDatabase('bob', 'bob_wp'),
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            '/tmp/g23-rollback',
        );

        $this->assertSame([['bob_wp', 'wp_posts', '/tmp/g23-rollback/bob_wp.wp_posts.sql.gz']], $record->dumps);
        $this->assertSame(['alice_wp' => 'bob_wp'], $replacement->result->databases);
        $this->assertSame(
            [['database' => 'bob_wp', 'table' => 'wp_posts', 'dump' => '/tmp/g23-rollback/bob_wp.wp_posts.sql.gz']],
            $replacement->rollbacks
        );
        $this->assertSame([], $replacement->createdTables);
        $this->assertSame([], $replacement->createdDatabases);
    }

    public function test_replace_tables_gives_triggers_to_the_target_user_the_source_user_maps_to(): void
    {
        $record = $this->recorder();
        $source = $this->userWithDatabase('alice', 'alice_wp');
        $source->mysqlUsers()->create(['user' => 'alice_wp']);
        $target = $this->userWithDatabase('bob', 'bob_wp');
        $target->mysqlUsers()->create(['user' => 'bob_wp']);

        $this->copier($record, ['bob_wp' => ['wp_posts']])->replaceTables(
            $source,
            $target,
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            '/tmp/g23-rollback',
        );

        $this->assertStringContainsString('s/DEFINER=`alice_wp`@`%`/DEFINER\n`bob_wp`@`%`/g', $record->scripts[0]);
        $this->assertStringContainsString('/DEFINER=`bob_no.definer`@`%`/g', $record->scripts[0]);
    }

    public function test_a_failure_on_the_second_table_restores_the_first(): void
    {
        $record = $this->recorder();
        $copier = $this->copier($record, ['bob_wp' => ['wp_posts', 'wp_options']], failTable: 'wp_options');

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
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('copy exploded', $e->getMessage());
        }

        $this->assertContains('/tmp/g23-rollback/bob_wp.wp_posts.sql.gz', array_column($record->restores, 1));
    }

    public function test_replace_tables_creates_a_target_database_the_project_does_not_own(): void
    {
        $record = $this->recorder();
        $replacement = $this->copier($record)->replaceTables(
            $this->userWithDatabase('alice', 'alice_wp'),
            $this->userWithDatabase('bob', 'bob_other'),
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            '/tmp/g23-rollback',
        );

        $this->assertSame(['bob_wp'], $replacement->createdDatabases);
        $this->assertSame(['bob_wp'], $record->created);
        $this->assertSame([['database' => 'bob_wp', 'table' => 'wp_posts']], $replacement->createdTables);
        $this->assertCount(1, $replacement->result->warnings);
    }

    public function test_a_target_database_replace_tables_creates_gets_the_source_users_grants(): void
    {
        $record = $this->recorder(privileges: ['alice_wp' => ['alice_wp' => 'SELECT, INSERT, TRIGGER']]);
        $source = $this->userWithDatabase('alice', 'alice_wp');
        $source->mysqlUsers()->create(['user' => 'alice_wp']);
        $target = $this->userWithDatabase('bob', 'bob_other');
        $target->mysqlUsers()->create(['user' => 'bob_wp']);

        $replacement = $this->copier($record)->replaceTables(
            $source,
            $target,
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            '/tmp/g23-rollback',
        );

        $this->assertSame(['bob_wp'], $replacement->createdDatabases);
        $this->assertSame([['bob_wp', 'bob_wp', 'SELECT, INSERT, TRIGGER']], $record->grants);
        $this->assertSame([], $replacement->result->warnings);
    }

    public function test_restore_tables_drops_a_created_table_and_database(): void
    {
        $record = $this->recorder(existing: ['bob_wp']);
        $replacement = new TableReplacement(
            new DatabaseCopyResult,
            DefinerMap::map('alice_', 'bob_', [], []),
            [],
            ['bob_wp'],
            [['database' => 'bob_wp', 'table' => 'wp_posts']],
        );

        $this->copier($record)->restoreTables($replacement);

        $this->assertCount(1, $record->scripts);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_posts`', $record->scripts[0]);
        $this->assertSame(['bob_wp'], $record->deleted);
    }

    public function test_a_failed_table_push_revokes_the_grants_it_gave_on_a_database_it_created(): void
    {
        $record = $this->recorder(existing: ['bob_wp'], privileges: ['alice_wp' => ['alice_wp' => 'SELECT, INSERT, TRIGGER']]);
        $source = $this->userWithDatabase('alice', 'alice_wp');
        $source->mysqlUsers()->create(['user' => 'alice_wp']);
        $target = $this->userWithDatabase('bob', 'bob_other');
        $target->mysqlUsers()->create(['user' => 'bob_wp']);

        try {
            $this->copier($record, failTable: 'wp_options')->replaceTables(
                $source,
                $target,
                [
                    ['database' => 'alice_wp', 'name' => 'wp_posts'],
                    ['database' => 'alice_wp', 'name' => 'wp_options'],
                ],
                '/tmp/g23-rollback',
            );
            $this->fail('the second table copy should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('copy exploded', $e->getMessage());
        }

        $this->assertSame([['bob_wp', 'bob_wp', 'SELECT, INSERT, TRIGGER']], $record->grants);
        $this->assertSame(['bob_wp'], $record->deleted);
        $this->assertSame([['bob_wp', 'bob_wp']], $record->revoked);
    }

    public function test_table_rollback_path_names_the_database_and_table(): void
    {
        $this->assertSame('/tmp/roll/bob_wp.wp_posts.sql.gz', DatabaseCopier::tableRollbackPath('/tmp/roll/', 'bob_wp', 'wp_posts'));
    }

    private function userWithDatabase(string $name, string $database): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name.'_']);
        $user->mysqlDatabases()->create(['database' => $database]);

        return $user->refresh();
    }

    /**
     * @param  array<string, array<string, string>>  $privileges  user => database => privileges
     */
    private function recorder(array $existing = [], array $privileges = []): object
    {
        return (object) [
            'created' => [],
            'deleted' => [],
            'dumps' => [],
            'restores' => [],
            'scripts' => [],
            'grants' => [],
            'revoked' => [],
            'existing' => $existing,
            'privileges' => $privileges,
        ];
    }

    /**
     * @param  array<string, list<string>>  $tableNames  target database => table names
     */
    private function copier(object $record, array $tableNames = [], ?string $failTable = null): DatabaseCopier
    {
        return new DatabaseCopier($this->mysqlDouble($record, $tableNames, $failTable));
    }

    /**
     * @param  array<string, list<string>>  $tableNames
     */
    private function mysqlDouble(object $record, array $tableNames, ?string $failTable): Mysql
    {
        return new class($record, $tableNames, $failTable) extends Mysql
        {
            /**
             * @param  array<string, list<string>>  $tableNames
             */
            public function __construct(
                private object $record,
                private array $tableNames,
                private ?string $failTable,
            ) {
                parent::__construct(null);
            }

            public function tables(): Tables
            {
                $names = $this->tableNames;

                return new class($names) extends Tables
                {
                    /**
                     * @param  array<string, list<string>>  $names
                     */
                    public function __construct(private array $names)
                    {
                        parent::__construct(new PDO('sqlite::memory:'));
                    }

                    public function names(string $database): array
                    {
                        return $this->names[$database] ?? [];
                    }

                    public function exists(string $database, string $table): bool
                    {
                        return in_array($table, $this->names($database), true);
                    }
                };
            }

            public function dumpToGzip(string $name, string $dest, ?string $table = null): void
            {
                $this->record->dumps[] = [$name, $table, $dest];
            }

            public function restoreFromGzip(string $name, string $sqlGzPath): void
            {
                $this->record->restores[] = [$name, $sqlGzPath];
            }

            public function runSitesDbScript(string $script, string $failurePrefix): void
            {
                if ($this->failTable !== null && str_contains($script, "'".$this->failTable."'")) {
                    throw new \RuntimeException('copy exploded');
                }

                $this->record->scripts[] = $script;
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

            public function databases(): Databases
            {
                $record = $this->record;

                return new class($record) extends Databases
                {
                    public function __construct(private object $record)
                    {
                        parent::__construct(new PDO('sqlite::memory:'));
                    }

                    public function createDatabase(string $name): void
                    {
                        $this->record->created[] = $name;
                    }

                    public function deleteDatabase(string $name): void
                    {
                        $this->record->deleted[] = $name;
                    }

                    public function databaseExists(string $name): bool
                    {
                        return in_array($name, $this->record->existing, true);
                    }
                };
            }
        };
    }
}
