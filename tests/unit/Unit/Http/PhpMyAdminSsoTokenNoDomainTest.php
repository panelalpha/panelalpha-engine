<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\MysqlSsoToken;
use Carbon\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A project with no main domain has no URL to sign on at, so it gets a 404 and no token. */
class PhpMyAdminSsoTokenNoDomainTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_a_project_without_a_main_domain_mints_no_token(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->postJson('/api/projects/alice/mysql/phpmyadmin-sso-token')->assertStatus(404);

        $this->assertSame(0, MysqlSsoToken::query()->count());
    }

    public function test_a_404_leaves_an_earlier_token_alone(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'dind']);
        MysqlSsoToken::create([
            'user_id' => $alice->id,
            'token' => 'earlier',
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->postJson('/api/projects/alice/mysql/phpmyadmin-sso-token')->assertStatus(404);

        $this->assertSame(['earlier'], MysqlSsoToken::query()->pluck('token')->all());
    }

    public function test_a_project_with_a_main_domain_gets_one_token(): void
    {
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');

        $url = $this->postJson('/api/projects/alice/mysql/phpmyadmin-sso-token')->assertSuccessful()->json('data.url');

        $token = MysqlSsoToken::query()->sole();
        $this->assertSame("https://shop.test/phpmyadmin/signon.php?pmassotoken={$token->token}", $url);
    }
}
