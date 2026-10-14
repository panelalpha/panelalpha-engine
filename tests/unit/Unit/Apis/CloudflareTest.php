<?php

namespace Tests\Unit\Apis;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareTest extends TestCase
{
    public function test_origin_service_url_uses_loopback(): void
    {
        $this->assertSame('http://127.0.0.1:9000', Cloudflare::originServiceUrl(9000));
    }

    public function test_upsert_hostname_ingress_replaces_existing_and_keeps_catch_all(): void
    {
        $existing = [
            [
                'hostname' => 'old.example.com',
                'service' => 'http://127.0.0.1:8080',
            ],
            [
                'hostname' => 'app.example.com',
                'service' => 'http://127.0.0.1:1',
            ],
            ['service' => 'http_status:404'],
        ];

        $updated = Cloudflare::upsertHostnameIngress(
            $existing,
            'app.example.com',
            'http://127.0.0.1:9000'
        );

        $this->assertCount(3, $updated);
        $this->assertSame('old.example.com', $updated[0]['hostname']);
        $this->assertSame('app.example.com', $updated[1]['hostname']);
        $this->assertSame('http://127.0.0.1:9000', $updated[1]['service']);
        $this->assertSame('http_status:404', $updated[2]['service']);
        $this->assertArrayNotHasKey('hostname', $updated[2]);
    }

    public function test_remove_hostname_ingress_drops_target_and_keeps_others(): void
    {
        $existing = [
            [
                'hostname' => 'keep.example.com',
                'service' => 'http://127.0.0.1:8080',
            ],
            [
                'hostname' => 'drop.example.com',
                'service' => 'http://127.0.0.1:9000',
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

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("Hostname 'other.org' is not under any Cloudflare zone");

        (new Cloudflare('test-token'))->findZoneForHostname('other.org');
    }
}
