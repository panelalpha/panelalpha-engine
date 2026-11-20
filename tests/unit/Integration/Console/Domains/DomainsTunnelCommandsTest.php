<?php

namespace Tests\Integration\Console\Domains;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `domain:tunnel:create` and `:delete` against a faked PanelAlpha Online proxy; a tunnel named after its own domain needs no vhost rebuild. */
class DomainsTunnelCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        Setting::flushCache();
        Http::preventStrayRequests();
        config(['connect.url' => 'https://connect.panelalpha.test']);
        Setting::set('default_ipv4', TestNetwork::public(9));
        $alice = $this->makeUser('alice', ['template' => 'dind']);
        $this->makeMainDomain($alice, 'alice.example.test');
        Domain::query()->create(['user_id' => $alice->id, 'domain' => 'shop.panelalpha.online', 'type' => 'addon', 'details' => ['document_root' => '/shop.panelalpha.online/public_html']]);
        $this->makeUser('bob');
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_create_refuses_missing_or_invalid_input(): void
    {
        $this->artisan('domain:tunnel:create a.example.com --hostname=b.example.com --project=alice --domain=alice.example.test')
            ->expectsOutput("Conflicting hostnames: argument 'a.example.com' vs --hostname='b.example.com'.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --domain=alice.example.test')
            ->expectsOutput('--project is required.')
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --project=alice')
            ->expectsOutput('--domain is required.')
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create --project=alice --domain=alice.example.test')
            ->expectsOutput('Tunnel hostname is required (positional argument or --hostname).')
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --project=alice --domain=alice.example.test --provider=ngrok')
            ->expectsOutput('--provider must be one of: '.implode(', ', Tunnel::PROVIDERS))
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create bad_host! --project=alice --domain=alice.example.test')
            ->expectsOutput("Invalid tunnel hostname 'bad_host!'.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --project=nobody --domain=alice.example.test')
            ->expectsOutput("Project 'nobody' not found.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --project=bob --domain=alice.example.test')
            ->expectsOutput("Domain 'alice.example.test' not found for project 'bob'.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:create a.example.com --project=alice --domain=alice.example.test')
            ->expectsOutput("Project 'alice' has no Cloudflare API token; set its cloudflare-api-token setting first.")
            ->assertExitCode(1);

        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_create_describes_a_cloudflare_tunnel_and_a_no_creates_nothing(): void
    {
        $alice = Domain::findByName('alice.example.test')->user;
        $alice->setDetails(['cloudflare_api_token' => 'cf-token']);
        $alice->save();

        $this->artisan('domain:tunnel:create --hostname=App.Example.com --project=alice --domain=alice.example.test')
            ->expectsOutput('Create tunnel')
            ->expectsOutputToContain('app.example.com')
            ->expectsOutput('Local domain keeps nginx routing; the public hostname is exposed via the tunnel.')
            ->expectsConfirmation('Create this tunnel?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_a_panelalpha_online_tunnel_is_created_and_deleted(): void
    {
        Http::fake([
            'connect.panelalpha.test/api/without-dns/sites' => Http::response(['status' => 'success', 'data' => ['path' => 'shop.panelalpha.online', 'valid_until' => null, 'model' => ['id' => 7]]], 200),
            'connect.panelalpha.test/api/without-dns/sites/*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->artisan('domain:tunnel:create shop.panelalpha.online --project=alice --domain=shop.panelalpha.online --provider=panelalpha --force')
            ->expectsOutputToContain("WDNS will send traffic to the local domain at this engine's public IP")
            ->expectsOutput("Tunnel 'shop.panelalpha.online' (panelalpha) attached to 'shop.panelalpha.online'.")
            ->expectsOutput('No proxy rules yet — run domains:set-proxy so the tunnel has an upstream.')
            ->assertExitCode(0);

        $tunnel = Tunnel::query()->sole();
        $this->assertSame(Tunnel::PROVIDER_PANELALPHA, $tunnel->provider);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['target_ip'] === TestNetwork::public(9));

        $this->artisan('domain:tunnel:delete shop.panelalpha.online --project=alice --domain=shop.panelalpha.online --force')
            ->expectsOutputToContain('PanelAlpha Online: deletes the remote WithoutDNS site via the licensing proxy')
            ->expectsOutput("Tunnel 'shop.panelalpha.online' deleted.")
            ->assertExitCode(0);

        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_a_tunnel_the_proxy_refuses_is_reported(): void
    {
        Http::fake(['connect.panelalpha.test/*' => Http::response(['status' => 'error', 'message' => 'Path taken'], 422)]);

        $this->artisan('domain:tunnel:create shop.panelalpha.online --project=alice --domain=shop.panelalpha.online --provider=panelalpha --force')
            ->expectsOutputToContain('Path taken')
            ->assertExitCode(1);

        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_delete_refuses_another_projects_or_another_domains_tunnel_and_a_no_keeps_it(): void
    {
        $domain = Domain::findByName('alice.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.example.com', 'details' => []]);

        $this->artisan('domain:tunnel:delete app.example.com --project=bob')
            ->expectsOutput("Tunnel 'app.example.com' not found for project 'bob'.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:delete app.example.com --project=alice --domain=shop.panelalpha.online')
            ->expectsOutput("Tunnel 'app.example.com' is not attached to domain 'shop.panelalpha.online'.")
            ->assertExitCode(1);
        $this->artisan('domain:tunnel:delete app.example.com --project=alice')
            ->expectsOutput('Only the tunnel hostname is removed. Local domain and proxy rules stay.')
            ->expectsConfirmation('Delete this tunnel?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);
        $this->assertSame(1, Tunnel::query()->count());
    }
}
