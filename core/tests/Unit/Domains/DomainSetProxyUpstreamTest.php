<?php

namespace Tests\Unit\Domains;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * domain:set-proxy writes the domain's project's own rules, so it may only
 * send the domain to that project's app: never to another project, an
 * engine service or the host.
 */
class DomainSetProxyUpstreamTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');
        $this->makeUser('bob');
    }

    public function test_a_domain_cannot_be_sent_anywhere_but_its_projects_app(): void
    {
        foreach (['bob:80', 'sites-db:3306', '172.25.0.2:3306', 'core:2011', '127.0.0.1:5000'] as $proxyTo) {
            $this->artisan("domain:set-proxy --domain=alice.example.test --proxy-to={$proxyTo} --force")
                ->expectsOutput("The upstream must be the project's own app, 'alice': "
                    . "a project's rule cannot reach another project, the engine's services or the host.")
                ->assertExitCode(1);
        }

        $this->assertSame(0, ProxyRule::query()->count());
    }
}
