<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Integrations\Tunnels\TunnelManager;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The lifetime a tunnel advertises and the labels the engine allocates for it,
 * without a provider call.
 */
class TunnelLifetimeTest extends TestCase
{
    use InMemoryDatabase;

    private User $alice;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->alice = $this->makeUser('alice');
        $this->domain = $this->makeMainDomain($this->alice, 'alice.test');
    }

    public function test_a_preview_hostname_is_a_preview_label_under_panelalpha_online(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression(
                '/^preview-[a-z0-9]{8}\.panelalpha\.online$/',
                TunnelManager::previewHostname()
            );
        }
    }

    public function test_a_tunnel_without_a_lifetime_never_expires(): void
    {
        $tunnel = $this->makeTunnel('shop.panelalpha.online');

        $this->assertSame(Tunnel::PURPOSE_DEFAULT, $tunnel->purpose());
        $this->assertNull($tunnel->expiresAt());
        $this->assertFalse($tunnel->isExpired());
    }

    public function test_the_engine_lifetime_is_read_back_in_utc(): void
    {
        $tunnel = $this->makeTunnel('shop.panelalpha.online', [
            'expires_at' => '2030-01-02 03:04:05',
        ]);

        $this->assertSame('2030-01-02T03:04:05+00:00', $tunnel->expiresAt()?->toIso8601String());
        $this->assertFalse($tunnel->isExpired());
    }

    public function test_the_proxys_own_lifetime_alone_never_expires_a_tunnel(): void
    {
        $tunnel = $this->makeTunnel('shop.panelalpha.online', [
            'valid_until' => now()->subDay()->toIso8601String(),
        ]);

        $this->assertNull($tunnel->expiresAt());
        $this->assertFalse($tunnel->isExpired());
        $this->assertNotContains($tunnel->id, Tunnel::expired()->pluck('id')->all());
    }

    public function test_purpose_preview_survives_and_an_unknown_one_reads_as_default(): void
    {
        $this->assertSame(Tunnel::PURPOSE_PREVIEW, $this->makeTunnel('a.panelalpha.online', ['purpose' => 'preview'])->purpose());
        $this->assertSame(Tunnel::PURPOSE_DEFAULT, $this->makeTunnel('b.panelalpha.online', ['purpose' => 'nonsense'])->purpose());
    }

    public function test_expired_lists_only_the_tunnels_past_their_engine_lifetime(): void
    {
        $gone = $this->makeTunnel('gone.panelalpha.online', ['expires_at' => now()->subMinute()->toIso8601String()]);
        $live = $this->makeTunnel('live.panelalpha.online', ['expires_at' => now()->addHour()->toIso8601String()]);
        $forever = $this->makeTunnel('forever.panelalpha.online');
        $proxyOnly = $this->makeTunnel('proxy.panelalpha.online', ['valid_until' => now()->subDay()->toIso8601String()]);

        $this->assertSame([$gone->id], Tunnel::expired()->pluck('id')->all());
        $this->assertFalse($live->isExpired());
        $this->assertFalse($forever->isExpired());
        $this->assertFalse($proxyOnly->isExpired());
    }

    /**
     * @param array<string, mixed> $details
     */
    private function makeTunnel(string $hostname, array $details = []): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $this->domain->id,
            'user_id' => $this->alice->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => $hostname,
            'details' => $details,
        ]);
    }
}
