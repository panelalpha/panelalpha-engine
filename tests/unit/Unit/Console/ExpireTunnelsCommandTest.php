<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `tunnels:expire` removes the tunnels whose lifetime has passed and leaves the
 * ones without one alone. The tunnel it removes is attached to the domain of
 * its own name, so the sibling vhost rebuild never runs.
 */
class ExpireTunnelsCommandTest extends TestCase
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

    public function test_it_removes_only_the_tunnels_past_their_lifetime(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $user = $this->makeUser('shop');
        $domain = $this->makeMainDomain($user, 'preview-abc12345.panelalpha.online');
        $expired = $this->makeTunnel($user->id, $domain->id, 'preview-abc12345.panelalpha.online', now()->subMinute()->toIso8601String());
        $live = $this->makeTunnel($user->id, $domain->id, 'preview-live0000.panelalpha.online', now()->addDay()->toIso8601String());
        $forever = $this->makeTunnel($user->id, $domain->id, 'preview-forever00.panelalpha.online', null);
        $proxyOnly = Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'preview-proxy000.panelalpha.online',
            'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW, 'valid_until' => now()->subDay()->toIso8601String()],
        ]);

        $this->artisan('tunnels:expire')
            ->expectsOutputToContain('Expired 1 of 1 tunnel(s).')
            ->assertExitCode(0);

        $this->assertNull($expired->fresh());
        $this->assertNotNull($live->fresh());
        $this->assertNotNull($forever->fresh());
        $this->assertNotNull($proxyOnly->fresh(), "The proxy's own valid_until is not the engine's to act on.");
        Http::assertSentCount(1);
    }

    public function test_it_says_when_there_is_nothing_to_do(): void
    {
        $this->artisan('tunnels:expire')
            ->expectsOutput('No expired tunnels.')
            ->assertExitCode(0);
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $events = collect($this->app->make(Kernel::class)->resolveConsoleSchedule()->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'tunnels:expire'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue((bool) $event->withoutOverlapping);
    }

    private function makeTunnel(int $userId, int $domainId, string $hostname, ?string $expiresAt): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $domainId,
            'user_id' => $userId,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => $hostname,
            'details' => array_filter([
                'purpose' => Tunnel::PURPOSE_PREVIEW,
                'expires_at' => $expiresAt,
            ], static fn (?string $value): bool => $value !== null),
        ]);
    }
}
