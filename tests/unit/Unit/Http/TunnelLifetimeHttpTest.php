<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST and GET /projects/{project}/domains/{domain}/tunnels with a purpose, a
 * ttl and an engine-allocated hostname. No provider is called for real: the
 * Connect endpoint is faked, and the one tunnel a test creates is attached to
 * the domain of its own name, so the sibling vhost rebuild never runs.
 */
class TunnelLifetimeHttpTest extends TestCase
{
    use InMemoryDatabase;

    private Domain $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => '203.0.113.10']);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        $this->shop = $this->makeMainDomain(
            $this->makeUser('shop', [], 'shop.panelalpha.online'),
            'shop.panelalpha.online'
        );
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_default_tunnel_without_a_ttl_never_expires(): void
    {
        $this->fakeConnect();

        $response = $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'hostname' => 'shop.panelalpha.online',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.purpose', 'default');
        $response->assertJsonPath('data.valid_until', null);
    }

    public function test_a_default_tunnel_with_an_explicit_ttl_expires(): void
    {
        $this->fakeConnect();

        $response = $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'hostname' => 'shop.panelalpha.online',
            'ttl_minutes' => 30,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.purpose', 'default');
        $this->assertDelta(30, (string) $response->json('data.valid_until'));
    }

    public function test_a_named_tunnel_records_the_ttl_it_was_given(): void
    {
        $this->fakeConnect();

        $response = $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'hostname' => 'shop.panelalpha.online',
            'purpose' => 'preview',
            'ttl_minutes' => 60,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.hostname', 'shop.panelalpha.online');
        $response->assertJsonPath('data.purpose', 'preview');
        $this->assertDelta(60, (string) $response->json('data.valid_until'));
    }

    public function test_a_ttl_below_the_floor_is_a_422_keyed_on_the_field(): void
    {
        $response = $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'purpose' => 'preview',
            'ttl_minutes' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ttl_minutes');
        $response->assertJsonPath('problems.0.field', 'ttl_minutes');
        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_a_ttl_above_thirty_days_is_a_422(): void
    {
        $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'purpose' => 'preview',
            'ttl_minutes' => 43201,
        ])->assertStatus(422)->assertJsonValidationErrors('ttl_minutes');
    }

    public function test_an_unknown_purpose_is_a_422(): void
    {
        $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'purpose' => 'staging',
        ])->assertStatus(422)->assertJsonValidationErrors('purpose');
    }

    public function test_cloudflare_without_a_hostname_is_a_422(): void
    {
        $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'provider' => 'cloudflare',
        ])->assertStatus(422)->assertJsonValidationErrors('hostname');
    }

    public function test_five_taken_labels_answer_409_with_the_stable_code(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'error', 'message' => 'Site already exists. Use PUT to update.'], 409),
        ]);

        $response = $this->postJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels', [
            'purpose' => 'preview',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'tunnel_hostname_unavailable');
        Http::assertSentCount(5);
        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_get_omits_a_tunnel_past_its_lifetime_and_exposes_purpose_and_valid_until(): void
    {
        $liveUntil = now()->addHour()->toIso8601String();
        $this->makeTunnel('gone.panelalpha.online', purpose: 'preview', expiresAt: now()->subMinute());
        $this->makeTunnel('live.panelalpha.online', purpose: 'preview', expiresAt: \Illuminate\Support\Carbon::parse($liveUntil));

        $response = $this->getJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels');

        $response->assertStatus(200);
        $this->assertSame(['live.panelalpha.online'], array_column($response->json('data'), 'hostname'));
        $response->assertJsonPath('data.0.purpose', 'preview');
        $response->assertJsonPath('data.0.valid_until', $liveUntil);
    }

    public function test_get_keeps_a_tunnel_carrying_only_the_proxys_expiry(): void
    {
        $proxyUntil = now()->subDay()->toIso8601String();
        Tunnel::create([
            'domain_id' => $this->shop->id,
            'user_id' => $this->shop->user_id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'proxy.panelalpha.online',
            'details' => [
                'purpose' => 'default',
                'valid_until' => $proxyUntil,
            ],
        ]);

        $response = $this->getJson('/api/projects/shop/domains/shop.panelalpha.online/tunnels');

        $response->assertStatus(200);
        $this->assertSame(['proxy.panelalpha.online'], array_column($response->json('data'), 'hostname'));
        $response->assertJsonPath('data.0.valid_until', null);
        $response->assertJsonPath('data.0.details.valid_until', $proxyUntil);
    }

    private function fakeConnect(): void
    {
        Http::fake([
            'connect.panelalpha.com/api/without-dns/sites' => Http::response([
                'status' => 'success',
                'data' => ['valid_until' => null, 'model' => ['id' => 7]],
            ], 200),
        ]);
    }

    private function makeTunnel(string $hostname, string $purpose, ?\DateTimeInterface $expiresAt): void
    {
        Tunnel::create([
            'domain_id' => $this->shop->id,
            'user_id' => $this->shop->user_id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => $hostname,
            'details' => array_filter([
                'purpose' => $purpose,
                'expires_at' => $expiresAt?->format('Y-m-d\TH:i:sP'),
            ], static fn (?string $value): bool => $value !== null),
        ]);
    }

    private function assertDelta(int $minutes, string $validUntil): void
    {
        $expected = now()->utc()->addMinutes($minutes)->getTimestamp();
        $actual = \Illuminate\Support\Carbon::parse($validUntil)->getTimestamp();

        $this->assertLessThanOrEqual(3, abs($expected - $actual), "valid_until '{$validUntil}' is not ~{$minutes} minutes away");
    }
}
