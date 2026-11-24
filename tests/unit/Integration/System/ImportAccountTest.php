<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Which account a copy into a project's database is imported as; no password is ever involved, and no database is refused one. */
class ImportAccountTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_application_database_is_imported_as_its_own_account(): void
    {
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bob_']);

        $this->assertSame('bob_app', $this->mysql([])->importAccount($bob, 'bob_app')->user);
    }

    public function test_any_other_database_is_imported_as_the_one_account_of_the_project_granted_on_it(): void
    {
        $bob = $this->projectWithUsers('bob_wp', 'bob_shop');

        $account = $this->mysql(['bob_wp' => ['bob_wp' => 'ALL PRIVILEGES'], 'bob_shop' => ['bob_shop' => 'SELECT']])
            ->importAccount($bob, 'bob_wp');

        $this->assertSame('bob_wp', $account->user);
    }

    public function test_a_database_no_account_is_granted_on_is_imported_as_a_temporary_account(): void
    {
        $bob = $this->projectWithUsers('bob_wp', 'bob_shop');

        $this->assertSame('', $this->mysql(['bob_shop' => ['bob_shop' => 'ALL PRIVILEGES']])->importAccount($bob, 'bob_wp')->user);
        $this->assertSame('', ImportAccount::temporary()->user);
    }

    public function test_of_several_granted_accounts_the_one_whose_grants_run_the_copied_objects_is_chosen(): void
    {
        $bob = $this->projectWithUsers('bob_ro', 'bob_rw', 'bob_wp');

        $grants = ['bob_ro' => ['bob_multi' => 'SELECT'], 'bob_rw' => ['bob_multi' => 'ALL PRIVILEGES'], 'bob_wp' => ['bob_wp' => 'ALL PRIVILEGES']];
        $this->assertSame('bob_rw', $this->mysql($grants)->importAccount($bob, 'bob_multi')->user);
        $named = ['bob_ro' => ['bob_wp' => 'ALL PRIVILEGES'], 'bob_wp' => ['bob_wp' => 'ALL PRIVILEGES']];
        $this->assertSame('bob_wp', $this->mysql($named)->importAccount($bob, 'bob_wp')->user);
    }

    private function projectWithUsers(string ...$users): User
    {
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bob_']);
        foreach ($users as $user) {
            $bob->mysqlUsers()->create(['user' => $user]);
        }

        return $bob->refresh();
    }

    /** @param  array<string, array<string, string>>  $grants  user => database => privileges */
    private function mysql(array $grants): Mysql
    {
        return new class($grants) extends Mysql
        {
            /** @param  array<string, array<string, string>>  $grants */
            public function __construct(private array $grants)
            {
                parent::__construct(null);
            }

            public function privileges(): Privileges
            {
                return new class($this->grants) extends Privileges
                {
                    /** @param  array<string, array<string, string>>  $grants */
                    public function __construct(private array $grants)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        return $this->grants[$user] ?? [];
                    }
                };
            }
        };
    }
}
