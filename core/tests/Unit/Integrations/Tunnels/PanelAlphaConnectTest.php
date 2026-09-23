<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelAlphaConnectTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_path_from_hostname_parses_fqdn_and_label(): void
    {
        $this->assertSame('admin-demo', PanelAlphaConnect::pathFromHostname('admin-demo.panelalpha.online'));
        $this->assertSame('admin-demo', PanelAlphaConnect::pathFromHostname('Admin-Demo'));
        $this->assertNull(PanelAlphaConnect::pathFromHostname('foo.example.com'));
        $this->assertNull(PanelAlphaConnect::pathFromHostname('a.b.panelalpha.online'));
        $this->assertNull(PanelAlphaConnect::pathFromHostname('panelalpha.online'));
    }

    public function test_create_site_posts_expected_payload_with_bearer(): void
    {
        Setting::setRuntimeSettings(['license_key' => 'TEST-KEY-123']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites' => Http::response([
                'status' => 'success',
                'data' => [
                    'path' => 'admin-demo.panelalpha.online',
                    'valid_until' => '2027-09-07 12:00:00',
                    'model' => ['id' => 42],
                ],
            ], 200),
        ]);

        $result = (new PanelAlphaConnect())->createSite(
            'admin.37-27-20-6.panelalpha.direct',
            '37.27.20.6',
            'admin-demo.panelalpha.online'
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites'
                && $request->hasHeader('Authorization', 'Bearer TEST-KEY-123')
                && $request['target_domain'] === 'admin.37-27-20-6.panelalpha.direct'
                && $request['target_ip'] === '37.27.20.6'
                && $request['path'] === 'admin-demo'
                && $request['plugin'] === 'PanelAlpha'
                && !array_key_exists('valid_until', $request->data())
                && !array_key_exists('source_domain', $request->data());
        });

        $this->assertSame('admin-demo', $result['path']);
        $this->assertSame('admin-demo.panelalpha.online', $result['path_fqdn']);
        $this->assertSame(42, $result['wdns_site_id']);
        $this->assertSame('2027-09-07 12:00:00', $result['valid_until']);
    }

    public function test_create_site_omits_bearer_when_no_license_key(): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites' => Http::response([
                'status' => 'success',
                'data' => [
                    'path' => 'no-key.panelalpha.online',
                    'id' => 7,
                ],
            ], 200),
        ]);

        (new PanelAlphaConnect())->createSite('local.test', '203.0.113.10', 'no-key');

        Http::assertSent(function ($request) {
            return !$request->hasHeader('Authorization')
                && $request['path'] === 'no-key';
        });
    }

    public function test_update_site_puts_fqdn_url(): void
    {
        Setting::setRuntimeSettings(['license_key' => 'KEY']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'path' => 'admin-demo.panelalpha.online',
                    'model' => ['id' => 43],
                ],
            ], 200),
        ]);

        $result = (new PanelAlphaConnect())->updateSite(
            'admin-demo.panelalpha.online',
            'local.test',
            '203.0.113.10'
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/admin-demo.panelalpha.online'
                && $request['target_domain'] === 'local.test'
                && $request['target_ip'] === '203.0.113.10';
        });

        $this->assertSame(43, $result['wdns_site_id']);
    }

    public function test_delete_site_calls_delete_on_fqdn(): void
    {
        Setting::setRuntimeSettings(['license_key' => 'KEY']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites/*' => Http::response(null, 204),
        ]);

        (new PanelAlphaConnect())->deleteSite('gone.panelalpha.online');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/gone.panelalpha.online';
        });
    }

    public function test_delete_site_treats_404_as_success(): void
    {
        Setting::clearRuntimeSettings();
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            '*' => Http::response(['status' => 'error', 'message' => 'Site not found'], 404),
        ]);

        (new PanelAlphaConnect())->deleteSite('missing.panelalpha.online');
        $this->assertTrue(true);
    }

    public function test_no_connect_configured_is_an_error_not_a_relative_url(): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => '']);

        Http::fake();

        $this->expectException(PanelAlphaException::class);
        $this->expectExceptionMessage('No PanelAlpha Connect is configured');

        (new PanelAlphaConnect())->createSite('local.test', '203.0.113.10', 'nowhere');
    }

    public function test_create_site_surfaces_409_conflict(): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => 'Site already exists. Use PUT to update.',
            ], 409),
        ]);

        $this->expectException(PanelAlphaException::class);
        $this->expectExceptionMessage('Site already exists. Use PUT to update.');

        (new PanelAlphaConnect())->createSite('local.test', '203.0.113.10', 'taken');
    }

    /**
     * The one case where a tunnel hostname may already exist as a domain: it
     * is that domain, so WithoutDNS forwards the public name and the
     * application is installed under the address its visitors use.
     */
    public function test_a_panelalpha_name_on_the_domain_of_the_same_name_serves_its_own_name(): void
    {
        $this->assertTrue(PanelAlphaConnect::servesItsOwnPublicName(
            'shop.panelalpha.online',
            'shop.panelalpha.online',
            'panelalpha'
        ));
    }

    public function test_case_and_padding_do_not_change_the_answer(): void
    {
        $this->assertTrue(PanelAlphaConnect::servesItsOwnPublicName(
            '  Shop.PanelAlpha.Online ',
            'shop.panelalpha.online',
            'PanelAlpha'
        ));
    }

    public function test_a_different_local_domain_is_not_serving_its_own_name(): void
    {
        $this->assertFalse(PanelAlphaConnect::servesItsOwnPublicName(
            'shop.178-104-84-45.panelalpha.direct',
            'shop.panelalpha.online',
            'panelalpha'
        ));
    }

    public function test_cloudflare_never_takes_the_exemption(): void
    {
        // A Cloudflare tunnel points a name in the customer's own zone at the
        // connector; a name the engine already serves directly is a conflict
        // there, not an arrangement.
        $this->assertFalse(PanelAlphaConnect::servesItsOwnPublicName(
            'shop.example.com',
            'shop.example.com',
            'cloudflare'
        ));
    }
}
