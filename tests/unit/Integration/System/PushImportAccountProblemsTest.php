<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System\Projects;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A push into a database whose account sites-db cannot add a sign-in to is refused before it is accepted. */
class PushImportAccountProblemsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_target_database_whose_account_does_not_sign_in_natively_is_a_problem(): void
    {
        [$stg, $live] = $this->pair();

        $problems = Projects::importAccountProblems($stg, $live, true, null, $this->mysql(native: []));

        $this->assertSame([[
            'field' => 'databases',
            'code' => 'import_account_unsupported',
            'message' => "'live_wp' is loaded as its account 'live_wp', which does not exist or does not sign in with "
                .'mysql_native_password, so it cannot be pushed into.',
        ]], $problems);
    }

    public function test_a_native_account_a_temporary_one_or_no_databases_is_no_problem(): void
    {
        [$stg, $live] = $this->pair();

        $this->assertSame([], Projects::importAccountProblems($stg, $live, true, null, $this->mysql(native: ['live_wp'])));
        $this->assertSame([], Projects::importAccountProblems($stg, $live, true, null, $this->mysql(native: [], granted: false)));
        $this->assertSame([], Projects::importAccountProblems($stg, $live, false, null, $this->mysql(native: [])));
    }

    public function test_a_table_push_checks_only_the_databases_of_its_tables(): void
    {
        [$stg, $live] = $this->pair();
        $stg->mysqlDatabases()->create(['database' => 'stg_shop']);

        $tables = [['database' => 'stg_shop', 'name' => 'orders']];

        $this->assertSame([], Projects::importAccountProblems($stg->refresh(), $live, true, $tables, $this->mysql(native: [])));
    }

    /** @return array{0: User, 1: User} */
    private function pair(): array
    {
        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_']);
        $stg->mysqlDatabases()->create(['database' => 'stg_wp']);
        $live = $this->makeUser('live', ['mysql_prefix' => 'live_']);
        $live->mysqlDatabases()->create(['database' => 'live_wp']);
        $live->mysqlUsers()->create(['user' => 'live_wp']);

        return [$stg->refresh(), $live->refresh()];
    }

    /** @param  list<string>  $native */
    private function mysql(array $native, bool $granted = true): Mysql
    {
        return new class($native, $granted) extends Mysql
        {
            /** @param  list<string>  $native */
            public function __construct(private array $native, private bool $granted)
            {
                parent::__construct(null);
            }

            public function privileges(): Privileges
            {
                return new class($this->granted) extends Privileges
                {
                    public function __construct(private bool $granted)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        return $this->granted ? ['live_wp' => 'ALL PRIVILEGES'] : [];
                    }
                };
            }

            public function users(): Users
            {
                return new class($this->native) extends Users
                {
                    /** @param  list<string>  $native */
                    public function __construct(private array $native)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function signsInNatively(string $name): bool
                    {
                        return in_array($name, $this->native, true);
                    }
                };
            }
        };
    }
}
