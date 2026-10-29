<?php

namespace Tests\Unit\Apis;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\Cloudflare\ZoneNotFoundException;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class CloudflareTest extends TestCase
{
    public function test_origin_service_url_uses_loopback(): void
    {
        $this->assertSame('http://'.TestNetwork::LOOPBACK.':9000', Cloudflare::originServiceUrl(9000));
    }

    public function test_upsert_hostname_ingress_replaces_existing_and_keeps_catch_all(): void
    {
        $existing = [
            [
                'hostname' => 'old.example.com',
                'service' => 'http://'.TestNetwork::LOOPBACK.':8080',
            ],
            [
                'hostname' => 'app.example.com',
                'service' => 'http://'.TestNetwork::LOOPBACK.':1',
            ],
            ['service' => 'http_status:404'],
        ];

        $updated = Cloudflare::upsertHostnameIngress(
            $existing,
            'app.example.com',
            'http://'.TestNetwork::LOOPBACK.':9000'
        );

        $this->assertCount(3, $updated);
        $this->assertSame('old.example.com', $updated[0]['hostname']);
        $this->assertSame('app.example.com', $updated[1]['hostname']);
        $this->assertSame('http://'.TestNetwork::LOOPBACK.':9000', $updated[1]['service']);
        $this->assertSame('http_status:404', $updated[2]['service']);
        $this->assertArrayNotHasKey('hostname', $updated[2]);
    }

    public function test_remove_hostname_ingress_drops_target_and_keeps_others(): void
    {
        $existing = [
            [
                'hostname' => 'keep.example.com',
                'service' => 'http://'.TestNetwork::LOOPBACK.':8080',
            ],
            [
                'hostname' => 'drop.example.com',
                'service' => 'http://'.TestNetwork::LOOPBACK.':9000',
            ],
            ['service' => 'http_status:404'],
        ];

        $updated = Cloudflare::removeHostnameIngress($existing, 'drop.example.com');

        $this->assertCount(2, $updated);
        $this->assertSame('keep.example.com', $updated[0]['hostname']);
        $this->assertSame('http_status:404', $updated[1]['service']);
    }

    public function test_find_zone_for_hostname_uses_longest_suffix(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'zone-com', 'name' => 'example.com'],
                    ['id' => 'zone-sub', 'name' => 'sub.example.com'],
                ],
            ], 200),
        ]);

        $client = new Cloudflare('test-token');
        $zone = $client->findZoneForHostname('a.sub.example.com');

        $this->assertSame('zone-sub', $zone['id']);
        $this->assertSame('sub.example.com', $zone['name']);
    }

    public function test_find_zone_for_hostname_errors_when_no_match(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'zone-com', 'name' => 'example.com'],
                ],
            ], 200),
        ]);

        $this->expectException(ZoneNotFoundException::class);
        $this->expectExceptionMessage("Hostname 'other.org' is not under any Cloudflare zone");

        (new Cloudflare('test-token'))->findZoneForHostname('other.org');
    }

    public function test_a_rate_limited_zone_lookup_is_not_a_missing_zone(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Rate limited']],
            ], 429),
        ]);

        try {
            (new Cloudflare('test-token'))->findZoneForHostname('shop.example.com');
            $this->fail('A rate-limited lookup must throw.');
        } catch (CloudflareException $e) {
            $this->assertNotInstanceOf(ZoneNotFoundException::class, $e);
            $this->assertTrue($e->isRateLimited());
            $this->assertTrue($e->isRefusedRequest());
        }
    }

    public function test_a_server_error_is_not_a_refused_request(): void
    {
        $e = new CloudflareException('Cloudflare API request failed (HTTP 502)', 502);

        $this->assertFalse($e->isRefusedRequest());
        $this->assertFalse($e->isRateLimited());
        $this->assertFalse((new CloudflareException('Cloudflare API returned a non-JSON response.'))->isRefusedRequest());
    }
}
