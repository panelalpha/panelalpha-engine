<?php

namespace Tests\Unit\Domains;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * domain:set-proxy writes a rule named after the domain, and only a container project's vhost renders one: a
 * traditional PHP project's own site answers for its domain, so the rule would never be served.
 */
class DomainSetProxyServerNameTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'default']), 'alice.example.test');
    }

    public function test_a_traditional_php_projects_domain_is_refused(): void
    {
        $this->artisan('domain:set-proxy --domain=alice.example.test --proxy-to=3000 --force')
            ->expectsOutput(
                'alice.example.test belongs to the traditional PHP project alice, whose own site answers for it, '
                .'so a rule named after it would never be served.'
            )
            ->assertExitCode(1);

        $this->assertSame(0, ProxyRule::query()->count());
    }
}
