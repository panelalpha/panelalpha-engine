<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\TunnelManager;
use App\Jobs\ExpireTunnel;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A tunnel created with a lifetime gets a pass due at that lifetime, and the
 * pass removes the tunnel then -- not at the next five-minute sweep, which
 * remains as the fallback for a job that never ran.
 */
class ExpireTunnelJobTest extends TestCase
{
    use InMemoryDatabase;

    private User $shop;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => TestNetwork::public(10)]);
        config(['connect.url' => 'https://connect.panelalpha.com']);

        $this->shop = $this->makeUser('shop', [], 'shop.panelalpha.online');
        $this->domain = $this->makeMainDomain($this->shop, 'shop.panelalpha.online');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_tunnel_with_a_lifetime_gets_its_pass_due_at_that_lifetime(): void
    {
        Queue::fake();
        $this->fakeConnect();

        $expiresAt = now()->utc()->addMinutes(15);
        $tunnel = TunnelManager::createTunnel(
            $this->shop,
            $this->domain,
            'shop.panelalpha.online',
            Tunnel::PROVIDER_PANELALPHA,
            Tunnel::PURPOSE_PREVIEW,
            $expiresAt
        );

        Queue::assertPushed(ExpireTunnel::class, function (ExpireTunnel $job) use ($tunnel, $expiresAt): bool {
            $this->assertSame($tunnel->id, $job->tunnelId);
            $this->assertSame($expiresAt->getTimestamp(), Carbon::parse($job->delay)->getTimestamp());

            return true;
        });
    }

    public function test_a_tunnel_without_a_lifetime_gets_no_pass(): void
    {
        Queue::fake();
        $this->fakeConnect();

        TunnelManager::createTunnel(
            $this->shop,
            $this->domain,
            'shop.panelalpha.online',
            Tunnel::PROVIDER_PANELALPHA
        );

        Queue::assertNotPushed(ExpireTunnel::class);
    }

    public function test_the_pass_removes_the_tunnel_once_its_lifetime_has_passed(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);
        $tunnel = $this->makeTunnel(now()->subSecond());

        (new ExpireTunnel($tunnel->id))->handle();

        $this->assertNull($tunnel->fresh());
        Http::assertSentCount(1);
    }

    public function test_the_pass_leaves_a_tunnel_that_is_still_alive(): void
    {
        Http::fake();
        $tunnel = $this->makeTunnel(now()->addMinute());

        (new ExpireTunnel($tunnel->id))->handle();

        $this->assertNotNull($tunnel->fresh());
        Http::assertNothingSent();
    }

    public function test_the_pass_of_a_tunnel_already_gone_does_nothing(): void
    {
        Http::fake();
        $tunnel = $this->makeTunnel(now()->subSecond());
        $tunnel->delete();

        (new ExpireTunnel($tunnel->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, Tunnel::query()->count());
    }

    private function makeTunnel(Carbon $expiresAt): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $this->domain->id,
            'user_id' => $this->shop->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop.panelalpha.online',
            'details' => [
                'purpose' => Tunnel::PURPOSE_PREVIEW,
                'expires_at' => $expiresAt->utc()->toIso8601String(),
            ],
        ]);
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
}
