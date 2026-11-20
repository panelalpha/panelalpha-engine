<?php

namespace Tests\Integration\Console\Domains;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `domain:set-proxy` and `domain:unset-proxy`: their refusals, the plan printed before asking, and what a "no" leaves behind. */
class DomainsProxyCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $alice = $this->makeUser('alice', ['template' => 'dind']);
        $main = $this->makeMainDomain($alice, 'alice.example.test');
        $main->setDetails(['aliases' => ['www.alice.example.test']]);
        $main->save();
        $this->makeUser('bob');
    }

    private function rule(int $listenPort, int $upstreamPort, bool $enabled = true): ProxyRule
    {
        return ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => $listenPort,
            'server_name' => 'alice.example.test',
            'upstream_host' => 'alice',
            'upstream_port' => $upstreamPort,
            'upstream_protocol' => 'http',
            'enabled' => $enabled,
        ]);
    }

    private function tunnel(): void
    {
        $domain = Domain::findByName('alice.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.example.com', 'details' => []]);
    }

    public function test_set_proxy_refuses_missing_or_mismatched_input(): void
    {
        $this->artisan('domain:set-proxy --proxy-to=3000')->expectsOutput('--domain is required.')->assertExitCode(1);
        $this->artisan('domain:set-proxy --domain=alice.example.test')->expectsOutput('--proxy-to is required (port or host:port).')->assertExitCode(1);
        $this->artisan('domain:set-proxy --domain=nowhere.example.test --proxy-to=3000')
            ->expectsOutput("Domain 'nowhere.example.test' not found.")
            ->assertExitCode(1);
        $this->artisan('domain:set-proxy --domain=alice.example.test --project=bob --proxy-to=3000')
            ->expectsOutput("Domain 'alice.example.test' belongs to project 'alice', not 'bob'.")
            ->assertExitCode(1);
        $this->artisan('domain:set-proxy --domain=alice.example.test --proxy-to=alice:notaport')
            ->expectsOutputToContain('notaport')
            ->assertExitCode(1);
        $this->artisan('domain:set-proxy --domain=alice.example.test --proxy-to=3000 --port=0')
            ->expectsOutput("Invalid --port '0'.")
            ->assertExitCode(1);

        $this->assertSame(0, ProxyRule::query()->count());
    }

    public function test_set_proxy_prints_the_plan_and_a_no_writes_nothing(): void
    {
        $this->rule(443, 8080, false);
        $this->tunnel();

        $this->artisan('domain:set-proxy --domain=www.alice.example.test --project=ALICE --proxy-to=3000 --port=8443 --port=443 --port=443')
            ->expectsOutput('Set proxy for alice.example.test (project: alice)')
            ->expectsOutputToContain('alice:8080 (disabled)')
            ->expectsOutputToContain('create')
            ->expectsOutputToContain('Cloudflare ingress will sync to http://'.TestNetwork::LOOPBACK.':3000 inside DinD.')
            ->expectsConfirmation('Apply these changes?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $rule = ProxyRule::query()->sole();
        $this->assertSame(8080, (int) $rule->upstream_port);
        $this->assertFalse((bool) $rule->enabled);
    }

    public function test_unset_proxy_refuses_missing_or_mismatched_input(): void
    {
        $this->artisan('domain:unset-proxy')->expectsOutput('--domain is required.')->assertExitCode(1);
        $this->artisan('domain:unset-proxy --domain=nowhere.example.test')
            ->expectsOutput("Domain 'nowhere.example.test' not found.")
            ->assertExitCode(1);
        $this->artisan('domain:unset-proxy --domain=alice.example.test --project=bob')
            ->expectsOutput("Domain 'alice.example.test' belongs to project 'alice', not 'bob'.")
            ->assertExitCode(1);
        $this->artisan('domain:unset-proxy --domain=alice.example.test --port=70000')
            ->expectsOutput("Invalid --port '70000'.")
            ->assertExitCode(1);
    }

    public function test_a_domain_without_its_project_is_refused(): void
    {
        Domain::query()->create(['user_id' => 999, 'domain' => 'orphan.example.test', 'type' => 'addon', 'details' => []]);

        $this->artisan('domain:set-proxy --domain=orphan.example.test --proxy-to=3000')
            ->expectsOutput('Domain has no project.')
            ->assertExitCode(1);
        $this->artisan('domain:unset-proxy --domain=orphan.example.test')
            ->expectsOutput('Domain has no project.')
            ->assertExitCode(1);
    }

    public function test_unset_proxy_with_no_rules_says_so_and_changes_nothing(): void
    {
        $this->rule(8443, 3000);

        $this->artisan('domain:unset-proxy --domain=alice.example.test')
            ->expectsOutput('No project HTTP proxy rules for alice.example.test on ports 80, 443.')
            ->assertExitCode(0);

        $this->assertSame(1, ProxyRule::query()->count());
    }

    public function test_unset_proxy_prints_the_plan_and_a_no_keeps_the_rules(): void
    {
        $this->rule(443, 3000);
        $this->rule(80, 3000, false);
        $this->tunnel();

        $this->artisan('domain:unset-proxy --domain=alice.example.test --port=443 --port=80 --port=8080')
            ->expectsOutput('Unset proxy for alice.example.test (project: alice)')
            ->expectsOutputToContain('alice:3000 (disabled)')
            ->expectsOutputToContain('skip')
            ->expectsOutput('Other listen ports (if any) are left unchanged.')
            ->expectsOutput('Attached tunnel hostname(s) will be removed from ingress until proxy is set again.')
            ->expectsConfirmation('Apply these changes?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(2, ProxyRule::query()->count());
    }

    public function test_unset_proxy_deletes_the_rules_and_reports_a_tunnel_it_cannot_sync(): void
    {
        $this->rule(443, 3000);
        $this->rule(80, 3000);
        $this->tunnel();

        $this->artisan('domain:unset-proxy --domain=alice.example.test --force')
            ->expectsOutput("Project 'alice' has no Cloudflare API token; set its cloudflare-api-token setting first.")
            ->assertExitCode(1);

        $this->assertSame(0, ProxyRule::query()->count());
    }

    public function test_unset_proxy_reports_a_tunnel_on_a_project_that_is_not_dind(): void
    {
        $bob = User::findByUsername('bob');
        $domain = $this->makeMainDomain($bob, 'bob.example.test');
        ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => 'bob', 'transport' => 'http', 'listen_ip' => '*', 'listen_port' => 443,
            'server_name' => 'bob.example.test', 'upstream_host' => 'bob', 'upstream_port' => 3000, 'upstream_protocol' => 'http', 'enabled' => true,
        ]);
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $bob->id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'bob.example.com', 'details' => []]);

        $this->artisan('domain:unset-proxy --domain=bob.example.test --force')
            ->expectsOutput("Cloudflare tunnels are only supported for DinD projects ('bob' is not DinD).")
            ->assertExitCode(1);
    }
}
