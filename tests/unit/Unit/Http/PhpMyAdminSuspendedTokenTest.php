<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A token is redeemed through any domain's /phpmyadmin, so a suspended project gets none. */
class PhpMyAdminSuspendedTokenTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
    }

    public function test_a_suspended_project_gets_no_token(): void
    {
        $this->postJson('/api/projects/alice/mysql/phpmyadmin-sso-token')->assertSuccessful();

        User::query()->where('username', 'alice')->update(['status' => 'suspended']);
        $this->postJson('/api/projects/alice/mysql/phpmyadmin-sso-token')->assertStatus(422);
    }
}
