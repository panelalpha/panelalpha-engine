<?php

namespace Tests\Integration\Console\Domains;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `domain:list` and `domain:show` read the domains, their aliases, tunnels and proxy rules back from the database. */
class DomainsListAndShowCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $alice = $this->makeUser('alice', ['template' => 'dind']);
        $main = $this->makeMainDomain($alice, 'alice.example.test');
        $main->setDetails(['aliases' => ['www.alice.example.test'], 'force_https_redirect' => true]);
        $main->save();
        Tunnel::create(['domain_id' => $main->id, 'user_id' => $alice->id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.example.com', 'details' => []]);
        Domain::query()->create([
            'user_id' => $alice->id,
            'domain' => 'shop.example.test',
            'type' => 'addon',
            'details' => ['document_root' => '/shop.example.test/public_html', 'ssl_disabled' => true, 'redirect_enabled' => true, 'redirect_url' => 'https://elsewhere.example.test'],
        ]);
        $bob = $this->makeUser('bob');
        $this->makeMainDomain($bob, 'bob.example.test');
    }

    private function proxyRule(string $serverName, int $listenPort, int $upstreamPort, bool $enabled): void
    {
        ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => $listenPort,
            'server_name' => $serverName,
            'upstream_host' => 'alice',
            'upstream_port' => $upstreamPort,
            'upstream_protocol' => 'http',
            'enabled' => $enabled,
        ]);
    }

    public function test_list_counts_every_projects_domains_aliases_and_tunnels(): void
    {
        $this->artisan('domain:list')
            ->expectsOutputToContain('bob.example.test')
            ->expectsOutputToContain('app.example.com (cloudflare)')
            ->expectsOutput('Total domains: 3 (+1 aliases, +1 tunnels)')
            ->expectsOutput('Total projects: 2')
            ->assertExitCode(0);
    }

    public function test_list_narrows_to_one_project(): void
    {
        $this->artisan('domain:list --project=bob')
            ->expectsOutputToContain('bob.example.test')
            ->doesntExpectOutputToContain('alice.example.test')
            ->expectsOutput('Total domains: 1 (+0 aliases, +0 tunnels)')
            ->expectsOutput('Total projects: 1')
            ->assertExitCode(0);
    }

    public function test_list_refuses_an_unknown_project(): void
    {
        $this->artisan('domain:list --project=nobody')
            ->expectsOutput("No project named 'nobody'")
            ->assertExitCode(1);
    }

    public function test_show_by_alias_prints_the_routing_and_the_tunnel_note(): void
    {
        $this->proxyRule('alice.example.test', 443, 3000, true);
        $this->proxyRule('www.alice.example.test', 8443, 4000, false);

        $this->artisan('domain:show WWW.alice.example.test')
            ->expectsOutputToContain('app.example.com (cloudflare)')
            ->expectsOutputToContain('443 -> alice:3000')
            ->expectsOutputToContain('8443 -> alice:4000 (disabled)')
            ->expectsOutputToContain('Tunnels expose public hostnames via the provider')
            ->doesntExpectOutputToContain('/alice.example.test/public_html')
            ->assertExitCode(0);
    }

    public function test_show_prints_the_document_root_of_a_domain_that_does_not_proxy(): void
    {
        $this->artisan('domain:show shop.example.test')
            ->expectsOutputToContain('https://elsewhere.example.test')
            ->expectsOutputToContain('disabled')
            ->expectsOutputToContain('/home/alice/shop.example.test/public_html')
            ->expectsOutputToContain('Files from document root are served only when the domain does not proxy on ports 80/443.')
            ->assertExitCode(0);
    }

    public function test_show_refuses_a_missing_or_empty_name(): void
    {
        $this->artisan('domain:show', ['domain' => ' '])
            ->expectsOutput('Domain name is required.')
            ->assertExitCode(1);
        $this->artisan('domain:show nowhere.example.test')
            ->expectsOutput("Domain 'nowhere.example.test' not found.")
            ->assertExitCode(1);
    }
}
