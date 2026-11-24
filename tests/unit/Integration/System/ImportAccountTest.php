<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Which account a copy into a project's database is imported as; no password is ever involved. */
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

    public function test_a_database_with_no_or_several_granted_accounts_is_refused(): void
    {
        $bob = $this->projectWithUsers('bob_wp', 'bob_shop');
        foreach ([[], ['bob_wp' => ['bob_wp' => 'ALL PRIVILEGES'], 'bob_shop' => ['bob_wp' => 'SELECT']]] as $grants) {
            try {
                $this->mysql($grants)->importAccount($bob, 'bob_wp');
                $this->fail('a database without exactly one account was given one');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("Database 'bob_wp' cannot be copied into", $e->getMessage());
                $this->assertStringContainsString(count($grants).' of the project', $e->getMessage());
            }
        }
    }

    public function test_an_account_without_a_name_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        new ImportAccount('');
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
