<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/** A disabled (parked) hand-made rule must not cost the domain its generated route on the next deploy. */
class ProxyRuleParkedRuleTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
        $this->hostListens("LISTEN 0 4096 0.0.0.0:2011 0.0.0.0:*");
    }

    public function test_a_parked_rule_leaves_the_generated_route_in_place(): void
    {
        ProxyRule::syncGeneratedHttpPair('alice', 'shop.test', 3000);
        $this->postJson('/api/proxy-rules', [
            'owner_scope' => 'user', 'username' => 'alice', 'transport' => 'http', 'listen_port' => 443,
            'server_name' => 'shop.test', 'upstream_host' => 'alice', 'upstream_port' => 8080, 'enabled' => false,
        ])->assertSuccessful();

        ProxyRule::syncGeneratedHttpPair('alice', 'shop.test', 3000);

        $this->assertSame(1, ProxyRule::query()->where('enabled', true)->where('listen_port', 443)->count());
    }
}
