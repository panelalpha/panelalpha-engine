<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestNetwork;
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
            self::directName('admin', TestNetwork::public(6)),
            TestNetwork::public(6),
            'admin-demo.panelalpha.online'
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites'
                && $request->hasHeader('Authorization', 'Bearer TEST-KEY-123')
                && $request['target_domain'] === self::directName('admin', TestNetwork::public(6))
                && $request['target_ip'] === TestNetwork::public(6)
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

        (new PanelAlphaConnect())->createSite('local.test', TestNetwork::public(10), 'no-key');

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
            TestNetwork::public(10)
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/admin-demo.panelalpha.online'
                && $request['target_domain'] === 'local.test'
                && $request['target_ip'] === TestNetwork::public(10);
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
        // Runtime settings, so the license key is not read from the settings table.
        Setting::setRuntimeSettings(['license_key' => 'TEST-KEY-123']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        Http::fake([
            '*' => Http::response(['status' => 'error', 'message' => 'Site not found'], 404),
        ]);

        (new PanelAlphaConnect())->deleteSite('missing.panelalpha.online');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/without-dns/sites/missing.panelalpha.online'));
    }

    public function test_no_connect_configured_is_an_error_not_a_relative_url(): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => '']);

        Http::fake();

        $this->expectException(PanelAlphaException::class);
        $this->expectExceptionMessage('No PanelAlpha Connect is configured');

        (new PanelAlphaConnect())->createSite('local.test', TestNetwork::public(10), 'nowhere');
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

        (new PanelAlphaConnect())->createSite('local.test', TestNetwork::public(10), 'taken');
    }

    /** @return array<string, array{string, callable(PanelAlphaConnect): mixed}> */
    public static function connectCalls(): array
    {
        return [
            'create' => ['create', fn (PanelAlphaConnect $connect) => $connect->createSite('local.test', TestNetwork::public(10), 'shop')],
            'update' => ['update', fn (PanelAlphaConnect $connect) => $connect->updateSite('shop.panelalpha.online', 'local.test', TestNetwork::public(10))],
            'delete' => ['delete', fn (PanelAlphaConnect $connect) => $connect->deleteSite('shop.panelalpha.online')],
        ];
    }

    #[DataProvider('connectCalls')]
    public function test_a_connect_that_cannot_be_reached_is_refused_like_any_other_failure(string $action, callable $call): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to connect.panelalpha.com port 443')]);

        $this->expectException(PanelAlphaException::class);
        $this->expectExceptionMessage(
            "PanelAlpha Online {$action} failed: PanelAlpha Connect could not be reached (cURL error 7: Failed to connect to connect.panelalpha.com port 443)."
        );

        $call(new PanelAlphaConnect());
    }

    public function test_a_tunnel_delete_with_connect_unreachable_is_logged_not_thrown(): void
    {
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        Http::fake(['*' => Http::failedConnection()]);
        Log::spy();

        PanelAlphaConnect::deleteTunnelRemote(new Tunnel(['hostname' => 'shop.panelalpha.online']));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => ($context['hostname'] ?? null) === 'shop.panelalpha.online'
                && str_contains((string) ($context['message'] ?? ''), 'PanelAlpha Connect could not be reached')
        );
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
            self::directName('shop', TestNetwork::public(45)),
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

    private static function directName(string $label, string $address): string
    {
        return $label.'.'.str_replace('.', '-', $address).'.panelalpha.direct';
    }
}
