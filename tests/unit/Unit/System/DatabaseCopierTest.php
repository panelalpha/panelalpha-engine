<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\DefinerMap;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use Symfony\Component\Process\Process;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DatabaseCopierTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_map_name_replaces_the_source_prefix(): void
    {
        $copier = new DatabaseCopier($this->mysqlDouble());

        $this->assertSame('bob_wp', $copier->mapName('alice_wp', 'alice_', 'bob_'));
    }

    public function test_map_name_prepends_when_the_name_is_unprefixed(): void
    {
        $copier = new DatabaseCopier($this->mysqlDouble());

        $this->assertSame('bob_wp', $copier->mapName('wp', 'alice_', 'bob_'));
    }

    public function test_rewrite_maps_the_definer_and_carries_the_source_schema(): void
    {
        $sed = DatabaseCopier::rewrite('alice_wp', 'bob_wp', $this->definers());

        $this->assertStringNotContainsString("s/DEFINER=`[^`]*`@`[^`]*`//g'", $sed);
        $this->assertStringContainsString('/^INSERT INTO /!{/^(/!s/`alice_wp`\./`bob_wp`./g}', $sed);
        $this->assertStringContainsString('/^INSERT INTO /!{/^(/!s/\balice_wp\./bob_wp./g}', $sed);
        $this->assertStringContainsString('s/DEFINER=`alice_wp`@`%`/DEFINER\n`bob_wp`@`%`/g', $sed);
        $this->assertStringContainsString('/DEFINER=`bob_no.definer`@`%`/g', $sed);
    }

    public function test_rewrite_leaves_row_data_untouched_and_rewrites_the_trigger(): void
    {
        $dump = implode("\n", [
            'LOCK TABLES `t` WRITE;',
            'INSERT INTO `t` VALUES',
            "(1,'alice_wp.example'),",
            "(2,'`alice_wp`.x'),",
            "(3,'plain');",
            'UNLOCK TABLES;',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER alice_wp.trg BEFORE INSERT ON alice_wp.t FOR EACH ROW SET NEW.v = UPPER(NEW.v) */;;',
        ]);

        $out = $this->runRewrite($dump);

        $this->assertStringContainsString("(1,'alice_wp.example')", $out);
        $this->assertStringContainsString("(2,'`alice_wp`.x')", $out);
        $this->assertStringContainsString('/*!50017 DEFINER=`bob_no.definer`@`%`*/', $out);
        $this->assertStringContainsString('TRIGGER bob_wp.trg', $out);
        $this->assertStringContainsString('ON bob_wp.t', $out);
    }

    public function test_rewrite_gives_every_definer_to_a_destination_account_and_never_root(): void
    {
        $dump = implode("\n", [
            'CREATE DEFINER=`alice_wp`@`%` PROCEDURE `p_own`()',
            'CREATE DEFINER=`root`@`localhost` PROCEDURE `p_root`()',
            'CREATE DEFINER=`alice_gone`@`%` PROCEDURE `p_gone`()',
            'CREATE DEFINER=`alice_wp`@`localhost` PROCEDURE `p_other_host`()',
            'CREATE DEFINER=`we``ird`@`%` FUNCTION `f_odd`() RETURNS int(11)',
            'CREATE DEFINER=`some_role` PROCEDURE `p_role`()',
            '/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */',
            "/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER t BEFORE INSERT ON t FOR EACH ROW SET @x = 'DEFINER=`alice_wp`@`%`' */;;",
            '/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`%`*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO DELETE FROM t */ ;;',
            'INSERT INTO `t` VALUES',
            "(1,'DEFINER=`root`@`localhost`');",
        ]);

        $lines = explode("\n", $this->runRewrite($dump));

        $this->assertSame('CREATE DEFINER=`bob_wp`@`%` PROCEDURE `p_own`()', $lines[0]);
        $this->assertSame('CREATE DEFINER=`bob_no.definer`@`%` PROCEDURE `p_root`()', $lines[1]);
        $this->assertSame('CREATE DEFINER=`bob_no.definer`@`%` PROCEDURE `p_gone`()', $lines[2]);
        $this->assertSame('CREATE DEFINER=`bob_no.definer`@`%` PROCEDURE `p_other_host`()', $lines[3]);
        $this->assertSame('CREATE DEFINER=`bob_no.definer`@`%` FUNCTION `f_odd`() RETURNS int(11)', $lines[4]);
        $this->assertSame('CREATE DEFINER=`bob_no.definer`@`%` PROCEDURE `p_role`()', $lines[5]);
        $this->assertSame('/*!50013 DEFINER=`bob_no.definer`@`%` SQL SECURITY DEFINER */', $lines[6]);
        $this->assertStringStartsWith('/*!50003 CREATE*/ /*!50017 DEFINER=`bob_no.definer`@`%`*/', $lines[7]);
        $this->assertStringContainsString("SET @x = 'DEFINER=`bob_wp`@`%`'", $lines[7]);
        $this->assertStringStartsWith('/*!50106 CREATE*/ /*!50117 DEFINER=`bob_no.definer`@`%`*/', $lines[8]);
        $this->assertSame("(1,'DEFINER=`root`@`localhost`');", $lines[10]);
        foreach (array_slice($lines, 0, 9) as $line) {
            $this->assertStringNotContainsString('`root`', $line);
        }
    }

    public function test_rewrite_escapes_a_database_name_for_the_regex(): void
    {
        $sed = DatabaseCopier::rewrite('alice-wp', 'bob-wp', $this->definers());

        $this->assertStringContainsString('alice\\-wp', $sed);
        $this->assertStringNotContainsString('s/\balice-wp\.', $sed);
    }

    public function test_copy_database_streams_a_dump_through_the_definer_rewrite(): void
    {
        $record = $this->recorder();
        $copier = new DatabaseCopier($this->mysqlDouble($record));

        $copier->copyDatabase('alice_wp', 'bob_wp', $this->definers());

        $this->assertSame(['bob_wp'], $record->created);
        $this->assertCount(1, $record->scripts);
        $this->assertStringContainsString('set -o pipefail;', $record->scripts[0]);
        $this->assertStringContainsString('mariadb-dump --single-transaction --routines --triggers --events', $record->scripts[0]);
        $this->assertStringContainsString("'alice_wp'", $record->scripts[0]);
        $this->assertStringContainsString("'bob_wp'", $record->scripts[0]);
        $this->assertStringContainsString('DEFINER=`bob_no.definer`@`%`', $record->scripts[0]);
        $this->assertStringContainsString('s/\balice_wp\./bob_wp./g', $record->scripts[0]);
    }

    public function test_copy_project_databases_maps_names_and_copies_owned_grants(): void
    {
        $source = $this->sourceWithDatabaseAndUser('alice');
        $this->makeUser('bob', ['mysql_prefix' => 'bob_']);
        $record = $this->recorder(
            sourcePrivileges: ['alice_wp' => 'SELECT, INSERT', 'someone_else' => 'SELECT'],
            targetPrivileges: [],
        );
        $copier = new DatabaseCopier($this->mysqlDouble($record));

        $result = $copier->copyProjectDatabases($source, User::findByUsernameOrFail('bob'), fn (string $line) => null);

        $this->assertSame(['alice_wp' => 'bob_wp'], $result->databases);
        $this->assertSame([['source' => 'alice_wp', 'dest' => 'bob_wp']], $result->databasePairs());
        $this->assertSame(['alice_wp' => 'bob_wp'], $result->users);
        $this->assertSame(['bob_wp'], $record->created);
        $this->assertSame(['bob_wp'], $record->users);
        $this->assertSame([['bob_wp', 'bob_wp', 'SELECT, INSERT']], $record->grants);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('someone_else', $result->warnings[0]);
        $this->assertArrayHasKey('bob_wp', $result->passwords);
        $this->assertNotSame('', $result->passwords['bob_wp']);
        $this->assertStringContainsString('s/DEFINER=`alice_wp`@`%`/DEFINER\n`bob_wp`@`%`/g', $record->scripts[0]);
    }

    public function test_replace_database_reapplies_target_grants_on_success(): void
    {
        $record = $this->recorder(targetPrivileges: ['bob_wp' => 'ALL PRIVILEGES']);
        $mysql = $this->mysqlDouble($record);
        $target = $this->targetWithDatabaseAndUser('bob', 'bob_wp');
        $copier = new DatabaseCopier($mysql);

        $rollback = $copier->dumpRollback('bob_wp', '/tmp/rq-rollback');
        $copier->replaceDatabase('alice_wp', 'bob_wp', $rollback, $target, $this->definers());

        $this->assertSame('/tmp/rq-rollback/bob_wp.sql.gz', $rollback);
        $this->assertSame(['/tmp/rq-rollback/bob_wp.sql.gz'], $record->dumps);
        $this->assertSame(['bob_wp'], $record->deleted);
        $this->assertSame(['bob_wp'], $record->created);
        $this->assertSame([['bob_wp', 'bob_wp', 'ALL PRIVILEGES']], $record->grants);
        $this->assertSame([], $record->restores);
    }

    public function test_replace_database_restores_the_rollback_when_the_copy_dies(): void
    {
        $record = $this->recorder();
        $mysql = $this->mysqlDouble($record, failStream: true);
        $target = $this->targetWithDatabaseAndUser('bob', 'bob_wp');

        try {
            (new DatabaseCopier($mysql))->replaceDatabase(
                'alice_wp',
                'bob_wp',
                '/tmp/rq-rollback/bob_wp.sql.gz',
                $target,
                $this->definers(),
            );
            $this->fail('the copy should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('copy exploded', $e->getMessage());
        }

        $this->assertSame(['/tmp/rq-rollback/bob_wp.sql.gz'], $record->restores);
    }

    private function definers(): DefinerMap
    {
        return DefinerMap::map('alice_', 'bob_', ['alice_wp'], ['bob_wp']);
    }

    private function runRewrite(string $dump): string
    {
        $process = Process::fromShellCommandline(DatabaseCopier::rewrite('alice_wp', 'bob_wp', $this->definers()));
        $process->setInput($dump);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }

    private function sourceWithDatabaseAndUser(string $name): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name . '_']);
        $user->mysqlDatabases()->create(['database' => $name . '_wp']);
        $user->mysqlUsers()->create(['user' => $name . '_wp']);

        return $user->refresh();
    }

    private function targetWithDatabaseAndUser(string $name, string $database): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name . '_']);
        $user->mysqlDatabases()->create(['database' => $database]);
        $user->mysqlUsers()->create(['user' => $database]);

        return $user->refresh();
    }

    /**
     * @param array<string, string> $sourcePrivileges
     * @param array<string, string> $targetPrivileges
     */
    private function recorder(array $sourcePrivileges = [], array $targetPrivileges = []): object
    {
        return (object) [
            'created'          => [],
            'deleted'          => [],
            'users'            => [],
            'dumps'            => [],
            'restores'         => [],
            'grants'           => [],
            'scripts'          => [],
            'sourcePrivileges' => $sourcePrivileges,
            'targetPrivileges' => $targetPrivileges,
        ];
    }

    private function mysqlDouble(?object $record = null, bool $failStream = false): Mysql
    {
        $record ??= $this->recorder();

        return new class ($record, $failStream) extends Mysql {
            public function __construct(private object $record, private bool $failStream)
            {
                parent::__construct(null);
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
                        $this->record->created[] = $name;
                    }

                    public function deleteDatabase(string $name): void
                    {
                        $this->record->deleted[] = $name;
                    }
                };
            }

            public function users(): Users
            {
                $record = $this->record;

                return new class ($record) extends Users {
                    public function __construct(private object $record)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function createUser(string $name, string $password): void
                    {
                        $this->record->users[] = $name;
                    }
                };
            }

            public function privileges(): Privileges
            {
                $record = $this->record;

                return new class ($record) extends Privileges {
                    public function __construct(private object $record)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        if ($user === 'alice_wp') {
                            return $this->record->sourcePrivileges;
                        }

                        return $this->record->targetPrivileges;
                    }

                    public function updatePrivileges(string $user, string $database, string $privileges): void
                    {
                        $this->record->grants[] = [$user, $database, $privileges];
                    }
                };
            }

            public function runSitesDbScript(string $script, string $failurePrefix): void
            {
                if ($this->failStream) {
                    throw new \RuntimeException('copy exploded');
                }

                $this->record->scripts[] = $script;
            }

            public function dumpToGzip(string $name, string $dest, ?string $table = null): void
            {
                $this->record->dumps[] = $dest;
            }

            public function restoreFromGzip(string $name, string $sqlGzPath): void
            {
                $this->record->restores[] = $sqlGzPath;
            }
        };
    }
}
