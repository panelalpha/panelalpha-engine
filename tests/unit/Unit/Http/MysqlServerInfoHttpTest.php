<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\System\Project\Dind\AppDatabase;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `mysql_server_info` reports the host the engine pins into a DinD app's
 * generated compose, so a caller who creates a database by hand and points its
 * app at the reported host is reachable from inside the account. It used to
 * report `config('env.USERS_DB_HOST')` raw, which is null when unset, while
 * the deploy still pinned the fallback name.
 */
class MysqlServerInfoHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('alice');
    }

    public function test_the_reported_host_is_the_name_the_deploy_pins(): void
    {
        config(['env.USERS_DB_HOST' => null]);

        $response = $this->getJson('/api/projects/alice/mysql/server-info');

        $response->assertOk()->assertJsonPath('data.host', AppDatabase::hostname());
        $this->assertSame('database-users.shared-hosting.palocal', $response->json('data.host'));
        $this->assertSame('3306', $response->json('data.port'));
    }

    public function test_a_configured_host_is_reported_verbatim(): void
    {
        config(['env.USERS_DB_HOST' => 'db.internal.example']);

        $this->getJson('/api/projects/alice/mysql/server-info')
            ->assertOk()
            ->assertJsonPath('data.host', 'db.internal.example');
    }
}
