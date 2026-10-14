<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Models\Setting;
use App\Models\Tunnel;
use App\System;
use App\System\Services\Webserver\RoutingCompiler;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Apps that route by Host to fixed extra names (Plunk's API_DOMAIN) get flat
 * siblings such as `api-<name>.panelalpha.online`: each is forwarded with its
 * own Host, and the domain's site answers to it.
 */
class SiblingTunnelTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => '203.0.113.10']);
        config(['connect.url' => 'https://connect.panelalpha.com']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_sibling_is_forwarded_with_its_own_host(): void
    {
        $this->fakeConnect('api-shop');
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.panelalpha.online'), 'shop.panelalpha.online');

        $tunnel = PanelAlphaConnect::createTunnel($domain->getUser(), $domain, 'api-shop.panelalpha.online');

        Http::assertSent(fn ($request): bool => $request['target_domain'] === 'api-shop.panelalpha.online'
            && $request['path'] === 'api-shop');
        $this->assertSame('api-shop.panelalpha.online', $tunnel->hostname);
    }

    public function test_the_projects_own_name_is_still_forwarded_as_the_domain(): void
    {
        $this->fakeConnect('shop');
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.panelalpha.online'), 'shop.panelalpha.online');

        PanelAlphaConnect::createTunnel($domain->getUser(), $domain, 'shop.panelalpha.online');

        Http::assertSent(fn ($request): bool => $request['target_domain'] === 'shop.panelalpha.online');
    }

    public function test_the_site_answers_to_its_siblings(): void
    {
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.panelalpha.online'), 'shop.panelalpha.online');
        foreach (['shop.panelalpha.online', 'api-shop.panelalpha.online', 's3-shop.panelalpha.online'] as $hostname) {
            Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_PANELALPHA, 'hostname' => $hostname, 'details' => []]);
        }
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'shop.example.com', 'details' => []]);

        $this->assertSame(['api-shop.panelalpha.online', 's3-shop.panelalpha.online'], $domain->siblingTunnelHostnames());

        $http = (new RoutingCompiler(new System()))->compile([$domain->fresh()])['http'];
        $this->assertSame(
            ['shop.panelalpha.online', 'api-shop.panelalpha.online', 's3-shop.panelalpha.online'],
            $http[0]['server_names']
        );
    }

    public function test_a_preview_is_forwarded_as_the_domain_it_previews(): void
    {
        $this->fakeConnect('preview-abcd1234');
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.example.com'), 'shop.example.com');

        $tunnel = PanelAlphaConnect::createTunnel($domain->getUser(), $domain, 'preview-abcd1234.panelalpha.online', forwardDomain: true);

        Http::assertSent(fn ($request): bool => $request['target_domain'] === 'shop.example.com'
            && $request['path'] === 'preview-abcd1234');
        $this->assertSame('preview-abcd1234.panelalpha.online', $tunnel->hostname);
        $this->assertSame('shop.example.com', $tunnel->getDetails()['target_domain'] ?? null);
        $this->assertSame('shop.example.com', $tunnel->forwardedHost());
    }

    public function test_a_tunnel_forwarded_as_its_own_name_records_no_target(): void
    {
        $this->fakeConnect('api-shop');
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.panelalpha.online'), 'shop.panelalpha.online');

        $tunnel = PanelAlphaConnect::createTunnel($domain->getUser(), $domain, 'api-shop.panelalpha.online');

        $this->assertArrayNotHasKey('target_domain', $tunnel->getDetails());
        $this->assertSame('api-shop.panelalpha.online', $tunnel->forwardedHost());
    }

    public function test_the_site_does_not_answer_to_a_preview_forwarded_as_the_domain(): void
    {
        $domain = $this->makeMainDomain($this->makeUser('shop', [], 'shop.example.com'), 'shop.example.com');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_PANELALPHA, 'hostname' => 'api-shop.panelalpha.online', 'details' => []]);
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_PANELALPHA, 'hostname' => 'preview-abcd1234.panelalpha.online', 'details' => ['target_domain' => 'shop.example.com', 'purpose' => Tunnel::PURPOSE_PREVIEW]]);

        $this->assertSame(['api-shop.panelalpha.online'], $domain->siblingTunnelHostnames());

        $http = (new RoutingCompiler(new System()))->compile([$domain->fresh()])['http'];
        $this->assertSame(['shop.example.com', 'api-shop.panelalpha.online'], $http[0]['server_names']);
    }

    private function fakeConnect(string $path): void
    {
        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites' => Http::response([
                'status' => 'success',
                'data' => ['path' => "{$path}.panelalpha.online", 'valid_until' => null, 'model' => ['id' => 7]],
            ], 200),
        ]);
    }
}
