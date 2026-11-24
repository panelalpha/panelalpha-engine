<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A push its import account cannot load is answered 422 up front instead of 202 and a failed task. */
class PushImportAccountHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_a_target_database_whose_account_does_not_sign_in_natively_is_refused(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindMysql(native: false);

        $response = $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'databases');
        $response->assertJsonPath('problems.0.code', 'import_account_unsupported');
        Queue::assertNothingPushed();
    }

    public function test_a_native_account_is_accepted(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindMysql(native: true);

        $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username])->assertStatus(202);
    }

    public function test_a_push_without_databases_checks_no_account(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindMysql(native: false);

        $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username, 'databases' => false])->assertStatus(202);
    }

    /** @return array{0: User, 1: User} */
    private function classicPair(): array
    {
        $from = $this->makeUser('pushacct-live', ['mysql_prefix' => 'alice_']);
        $from->mysqlDatabases()->create(['database' => 'alice_wp']);
        $to = $this->makeUser('pushacct-stg', ['mysql_prefix' => 'bob_']);
        $to->mysqlDatabases()->create(['database' => 'bob_wp']);
        $to->mysqlUsers()->create(['user' => 'bob_wp']);
        $to->staging = $from->id;
        $to->save();

        return [$from->refresh(), $to->refresh()];
    }

    private function bindMysql(bool $native): void
    {
        $mysql = new class($native) extends Mysql
        {
            public function __construct(private bool $native)
            {
                parent::__construct(null);
            }

            public function privileges(): Privileges
            {
                return new class extends Privileges
                {
                    public function __construct()
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function showPrivileges(string $user): array
                    {
                        return ['bob_wp' => 'ALL PRIVILEGES'];
                    }
                };
            }

            public function users(): Users
            {
                return new class($this->native) extends Users
                {
                    public function __construct(private bool $native)
                    {
                        parent::__construct(new PDO('sqlite::memory:'), '%');
                    }

                    public function signsInNatively(string $name): bool
                    {
                        return $this->native;
                    }
                };
            }
        };

        $this->app->instance(System::class, new class($mysql) extends System
        {
            public function __construct(private Mysql $mysql) {}

            public function mysql(): Mysql
            {
                return $this->mysql;
            }
        });
    }
}
