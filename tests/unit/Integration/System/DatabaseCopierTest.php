<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\AccountMap;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\Support\SitesDbRequest;
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

    public function test_copy_database_asks_sites_db_to_import_as_the_account(): void
    {
        $record = $this->recorder();
        $copier = new DatabaseCopier($this->mysqlDouble($record));

        $copier->copyDatabase('alice_wp', 'bob_wp', $this->account());

        $this->assertSame(['bob_wp'], $record->created);
        $this->assertCount(1, $record->scripts);
        $this->assertStringEndsWith(
            " compose.sitesDbCopy -- /opt/panelalpha/shared-hosting alice_wp bob_wp '' bob_wp",
            $record->scripts[0],
        );
        $this->assertSame([['bob_wp', 0]], $record->imports);
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
        $this->assertStringEndsWith(" alice_wp bob_wp '' bob_wp", $record->scripts[0]);
        $this->assertSame([['bob_wp', 1]], $record->imports);
    }

    public function test_a_project_copy_refuses_a_database_without_exactly_one_copied_account_to_import_as(): void
    {
        foreach (['no account' => [], 'two accounts' => ['alice_wp' => 'ALL PRIVILEGES']] as $case => $sharedGrant) {
            $source = $this->sourceWithDatabaseAndUser('alice');
            if ($sharedGrant !== []) {
                $source->mysqlUsers()->create(['user' => 'alice_wp2']);
            }
            $this->makeUser('bob', ['mysql_prefix' => 'bob_']);
            $record = $this->recorder(sourcePrivileges: $case === 'no account' ? [] : $sharedGrant);
            $copier = new DatabaseCopier($this->mysqlDouble($record, sharedSourcePrivileges: $sharedGrant !== []));

            try {
                $copier->copyProjectDatabases($source->refresh(), User::findByUsernameOrFail('bob'), fn (string $line) => null);
                $this->fail("{$case}: the copy ran without one account to import as");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("Database 'bob_wp' cannot be copied", $e->getMessage(), $case);
            }
            $this->assertSame([], $record->scripts, $case);

            User::query()->delete();
        }
    }

    public function test_replace_database_reapplies_target_grants_on_success(): void
    {
        $record = $this->recorder(targetPrivileges: ['bob_wp' => 'ALL PRIVILEGES']);
        $mysql = $this->mysqlDouble($record);
        $target = $this->targetWithDatabaseAndUser('bob', 'bob_wp');
        $copier = new DatabaseCopier($mysql);

        $rollback = $copier->dumpRollback('bob_wp', '/tmp/rq-rollback');
        $copier->replaceDatabase('alice_wp', 'bob_wp', $rollback, $target, $this->account());

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
                $this->account(),
            );
            $this->fail('the copy should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('copy exploded', $e->getMessage());
        }

        $this->assertSame(['/tmp/rq-rollback/bob_wp.sql.gz'], $record->restores);
    }

    public function test_a_database_a_push_creates_gets_the_source_users_grants_for_the_target_user(): void
    {
        $record = $this->recorder(sourcePrivileges: ['alice_wp' => 'SELECT, INSERT, TRIGGER', 'alice_other' => 'SELECT']);

        $warnings = (new DatabaseCopier($this->mysqlDouble($record)))->grantCreatedDatabase('alice_wp', 'bob_wp', AccountMap::map('alice_', 'bob_', ['alice_wp'], ['bob_wp']));

        $this->assertSame([['bob_wp', 'bob_wp', 'SELECT, INSERT, TRIGGER']], $record->grants);
        $this->assertSame([], $warnings);
    }

    public function test_a_database_a_push_creates_without_a_target_user_to_grant_says_so(): void
    {
        $record = $this->recorder(sourcePrivileges: ['alice_wp' => 'ALL PRIVILEGES']);

        $warnings = (new DatabaseCopier($this->mysqlDouble($record)))->grantCreatedDatabase(
            'alice_wp',
            'bob_wp',
            AccountMap::map('alice_', 'bob_', ['alice_wp'], []),
        );

        $this->assertSame([], $record->grants);
        $this->assertSame(
            ["Created database 'bob_wp' with no grants: no user of the target maps to a source user with grants on 'alice_wp'."],
            $warnings
        );
    }

    private function account(): ImportAccount
    {
        return new ImportAccount('bob_wp');
    }

    private function sourceWithDatabaseAndUser(string $name): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name.'_']);
        $user->mysqlDatabases()->create(['database' => $name.'_wp']);
        $user->mysqlUsers()->create(['user' => $name.'_wp']);

        return $user->refresh();
    }

    private function targetWithDatabaseAndUser(string $name, string $database): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name.'_']);
        $user->mysqlDatabases()->create(['database' => $database]);
        $user->mysqlUsers()->create(['user' => $database]);

        return $user->refresh();
    }

    /**
     * @param  array<string, string>  $sourcePrivileges
     * @param  array<string, string>  $targetPrivileges
     */
    private function recorder(array $sourcePrivileges = [], array $targetPrivileges = []): object
    {
        return (object) [
            'created' => [],
            'deleted' => [],
            'users' => [],
            'dumps' => [],
            'restores' => [],
            'grants' => [],
            'scripts' => [],
            'imports' => [],
            'sourcePrivileges' => $sourcePrivileges,
            'targetPrivileges' => $targetPrivileges,
        ];
    }

    private function mysqlDouble(?object $record = null, bool $failStream = false, bool $sharedSourcePrivileges = false): Mysql
    {
        $record ??= $this->recorder();

        return new class($record, $failStream, $sharedSourcePrivileges) extends Mysql
        {
            public function __construct(private object $record, private bool $failStream, private bool $sharedSourcePrivileges)
            {
                parent::__construct(null);
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
                };
            }

            public function users(): Users
            {
                $record = $this->record;

                return new class($record) extends Users
                {
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

                return new class($record, $this->sharedSourcePrivileges) extends Privileges
                {
                    public function __construct(private object $record, private bool $sharedSourcePrivileges)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        if ($user === 'alice_wp' || ($this->sharedSourcePrivileges && str_starts_with($user, 'alice_'))) {
                            return $this->record->sourcePrivileges;
                        }

                        $granted = $this->record->targetPrivileges;
                        foreach ($this->record->grants as [$grantee, $database, $privileges]) {
                            if ($grantee === $user) {
                                $granted[$database] = $privileges;
                            }
                        }

                        return $granted;
                    }

                    public function updatePrivileges(string $user, string $database, string $privileges): void
                    {
                        $this->record->grants[] = [$user, $database, $privileges];
                    }
                };
            }

            public function copyInSitesDb(string $from, string $to, ?string $table, ImportAccount $account, string $failurePrefix): void
            {
                $this->recordScript(SitesDbRequest::copy($from, $to, $table, $account), $failurePrefix);
                $this->record->imports[] = [$account->user, count($this->record->grants)];
            }

            public function dropTableInSitesDb(string $database, string $table): void
            {
                $this->recordScript(SitesDbRequest::drop($database, $table), 'table drop failed');
            }

            private function recordScript(string $script, string $failurePrefix): void
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
