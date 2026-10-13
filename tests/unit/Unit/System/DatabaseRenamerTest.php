<?php

namespace Tests\Unit\System;

use App\Models\MysqlDatabase;
use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseRenamer;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DatabaseRenamerTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_tables_only_database_moves_by_metadata(): void
    {
        $record = $this->recorder(tables: ['posts', 'users'], privileges: ['shop_old' => 'ALL PRIVILEGES']);
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        $this->renamer($record)->rename($user, $row, 'shop_new');

        $this->assertSame(['shop_new'], $record->created);
        $this->assertSame([['shop_old', 'shop_new', ['posts', 'users']]], $record->renamed);
        $this->assertSame([], $record->copies);
        $this->assertSame(['shop_old'], $record->deleted);
        $this->assertSame([['shop_user', 'shop_new', 'ALL PRIVILEGES']], $record->grants);
        $this->assertSame([['shop_user', 'shop_old']], $record->revokes);
        $this->assertSame('shop_new', $row->fresh()->database);
    }

    public function test_a_database_with_objects_beyond_tables_is_copied(): void
    {
        $record = $this->recorder(
            tables: ['posts'],
            beyondTables: true,
            privileges: ['shop_old' => 'SELECT, INSERT'],
        );
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        $this->renamer($record)->rename($user, $row, 'shop_new');

        $this->assertSame([], $record->renamed);
        $this->assertCount(1, $record->scripts);
        $this->assertStringContainsString('mariadb-dump', $record->scripts[0]);
        $this->assertSame(['shop_new'], $record->created);
        $this->assertSame(['shop_old'], $record->deleted);
        $this->assertSame([['shop_user', 'shop_new', 'SELECT, INSERT']], $record->grants);
        $this->assertSame('shop_new', $row->fresh()->database);
        $this->assertStringContainsString('s/DEFINER=`shop_user`@`%`/DEFINER\n`shop_user`@`%`/g', $record->scripts[0]);
        $this->assertStringContainsString('/DEFINER=`shop_no.definer`@`%`/g', $record->scripts[0]);
    }

    public function test_a_copy_failure_leaves_the_old_database_and_its_grants(): void
    {
        $record = $this->recorder(
            tables: ['posts'],
            beyondTables: true,
            privileges: ['shop_old' => 'ALL PRIVILEGES'],
            failStream: true,
        );
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        try {
            $this->renamer($record)->rename($user, $row, 'shop_new');
            $this->fail('the copy should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('copy exploded', $e->getMessage());
        }

        $this->assertSame(['shop_new'], $record->deleted);
        $this->assertNotContains('shop_old', $record->deleted);
        $this->assertSame([['shop_user', 'shop_old', 'ALL PRIVILEGES']], $record->grants);
        $this->assertSame('shop_old', $row->fresh()->database);
    }

    public function test_a_grant_failure_after_a_table_move_moves_the_tables_back_before_the_drop(): void
    {
        $record = $this->recorder(
            tables: ['posts', 'users'],
            privileges: ['shop_old' => 'ALL PRIVILEGES'],
            failGrantOn: 'shop_new',
        );
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        try {
            $this->renamer($record)->rename($user, $row, 'shop_new');
            $this->fail('the grant should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('grant exploded', $e->getMessage());
        }

        $this->assertSame([
            ['shop_old', 'shop_new', ['posts', 'users']],
            ['shop_new', 'shop_old', ['posts', 'users']],
        ], $record->renamed);
        $this->assertSame(['rename', 'rename', 'drop shop_new'], $record->order);
        $this->assertSame(['shop_new'], $record->deleted);
        $this->assertSame([['shop_user', 'shop_old', 'ALL PRIVILEGES']], $record->grants);
        $this->assertSame('shop_old', $row->fresh()->database);
    }

    public function test_tables_that_cannot_move_back_are_kept_in_the_new_database(): void
    {
        $record = $this->recorder(
            tables: ['posts'],
            privileges: ['shop_old' => 'ALL PRIVILEGES'],
            failGrantOn: 'shop_new',
            failRenameBack: true,
        );
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        try {
            $this->renamer($record)->rename($user, $row, 'shop_new');
            $this->fail('the grant should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('grant exploded', $e->getMessage());
            $this->assertStringContainsString("are kept in 'shop_new'", $e->getMessage());
        }

        $this->assertSame([], $record->deleted);
        $this->assertSame([['shop_user', 'shop_old', 'ALL PRIVILEGES']], $record->grants);
        $this->assertSame('shop_old', $row->fresh()->database);
    }

    public function test_a_grant_failure_after_a_dump_copy_drops_the_copy(): void
    {
        $record = $this->recorder(
            tables: ['posts'],
            beyondTables: true,
            privileges: ['shop_old' => 'ALL PRIVILEGES'],
            failGrantOn: 'shop_new',
        );
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        try {
            $this->renamer($record)->rename($user, $row, 'shop_new');
            $this->fail('the grant should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('grant exploded', $e->getMessage());
        }

        $this->assertSame([], $record->renamed);
        $this->assertSame(['shop_new'], $record->deleted);
        $this->assertSame('shop_old', $row->fresh()->database);
    }

    public function test_an_existing_destination_is_refused_untouched(): void
    {
        $record = $this->recorder(exists: ['shop_new'], privileges: ['shop_old' => 'ALL PRIVILEGES']);
        [$user, $row] = $this->projectWithDatabase('shop', 'shop_old', 'shop_user');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database 'shop_new' already exists");

        try {
            $this->renamer($record)->rename($user, $row, 'shop_new');
        } finally {
            $this->assertSame([], $record->created);
            $this->assertSame([], $record->deleted);
            $this->assertSame('shop_old', $row->fresh()->database);
        }
    }

    /**
     * @return array{0: User, 1: MysqlDatabase}
     */
    private function projectWithDatabase(string $username, string $database, string $dbUser): array
    {
        $user = $this->makeUser($username, ['mysql_prefix' => $username . '_']);
        $user->mysqlUsers()->create(['user' => $dbUser]);
        /** @var MysqlDatabase $row */
        $row = $user->mysqlDatabases()->create(['database' => $database]);

        return [$user->refresh(), $row];
    }

    /**
     * @param list<string>          $tables
     * @param list<string>          $exists
     * @param array<string, string> $privileges
     */
    private function recorder(
        array $tables = [],
        array $exists = [],
        array $privileges = [],
        bool $beyondTables = false,
        bool $failStream = false,
        ?string $failGrantOn = null,
        bool $failRenameBack = false,
    ): object {
        return (object) [
            'tables'         => $tables,
            'exists'         => $exists,
            'privileges'     => $privileges,
            'beyondTables'   => $beyondTables,
            'failStream'     => $failStream,
            'failGrantOn'    => $failGrantOn,
            'failRenameBack' => $failRenameBack,
            'order'          => [],
            'created'        => [],
            'deleted'        => [],
            'renamed'        => [],
            'copies'         => [],
            'scripts'        => [],
            'grants'         => [],
            'revokes'        => [],
        ];
    }

    private function renamer(object $record): DatabaseRenamer
    {
        $mysql = $this->mysqlDouble($record);

        return new DatabaseRenamer($mysql, new DatabaseCopier($mysql));
    }

    private function mysqlDouble(object $record): Mysql
    {
        return new class ($record) extends Mysql {
            public function __construct(private object $record)
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

                    public function databaseExists(string $name): bool
                    {
                        return in_array($name, $this->record->exists, true);
                    }

                    public function createDatabase(string $name): void
                    {
                        $this->record->created[] = $name;
                    }

                    public function deleteDatabase(string $name): void
                    {
                        $this->record->deleted[] = $name;
                        $this->record->order[] = 'drop ' . $name;
                    }

                    public function tables(string $name): array
                    {
                        return $this->record->tables;
                    }

                    public function hasObjectsBeyondTables(string $name): bool
                    {
                        return $this->record->beyondTables;
                    }

                    /**
                     * @param list<string> $tables
                     */
                    public function renameTables(string $from, string $to, array $tables): void
                    {
                        if ($this->record->failRenameBack && $this->record->renamed !== []) {
                            throw new \RuntimeException('rename back exploded');
                        }

                        $this->record->renamed[] = [$from, $to, $tables];
                        $this->record->order[] = 'rename';
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
                        return $this->record->privileges;
                    }

                    public function updatePrivileges(string $user, string $database, string $privileges): void
                    {
                        if ($database === $this->record->failGrantOn) {
                            throw new \RuntimeException('grant exploded');
                        }

                        $this->record->grants[] = [$user, $database, $privileges];
                    }

                    public function revokeIfExists(string $user, string $database): void
                    {
                        $this->record->revokes[] = [$user, $database];
                    }
                };
            }

            public function runSitesDbScript(string $script, string $failurePrefix): void
            {
                if ($this->record->failStream) {
                    throw new \RuntimeException('copy exploded');
                }

                $this->record->scripts[] = $script;
                $this->record->copies[] = $script;
            }
        };
    }
}
