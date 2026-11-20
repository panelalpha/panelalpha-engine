<?php

namespace Tests\Integration\Integrations\Tunnels;

use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\Services\ServiceManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Which tunnels may be created on a domain, and what removing one, or every one a domain
 * holds, does at the provider and to the account's connector.
 */
class TunnelManagerTeardownTest extends TestCase
{
    use InMemoryDatabase;

    private ServiceManager&MockInterface $services;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->services = Mockery::mock(ServiceManager::class);
        Setting::setRuntimeSettings(['license_key' => '']);
        config(['connect.url' => 'https://connect.panelalpha.test']);
        Http::fake(['connect.panelalpha.test/*' => Http::response(null, 204)]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> */
    public static function refusals(): array
    {
        return [
            'no hostname' => ['  ', 'cloudflare', 'Tunnel hostname is required.'],
            'unknown provider' => ['www.example.test', 'ngrok', "Unsupported tunnel provider 'ngrok'. Supported: cloudflare, panelalpha"],
            'name already a tunnel' => ['Taken.Panelalpha.Online', 'panelalpha', "Hostname 'taken.panelalpha.online' is already in use."],
            'name already a domain' => ['other.example.test', 'cloudflare', "Hostname 'other.example.test' is already in use."],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_tunnel_that_cannot_be_created_is_refused_with_its_reason(string $hostname, string $provider, string $message): void
    {
        $user = $this->user('shop');
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->makeMainDomain($user, 'other.example.test');
        $this->tunnel($user, $domain, 'taken.panelalpha.online', Tunnel::PROVIDER_PANELALPHA);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage($message);

        TunnelManager::assertCreatable($user, $domain, $hostname, $provider);
    }

    public function test_a_domain_of_another_project_takes_no_tunnel(): void
    {
        $owner = $this->user('owner');
        $domain = $this->makeMainDomain($owner, 'owner.example.test');

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('Domain does not belong to this project.');

        TunnelManager::assertCreatable($this->user('intruder'), $domain, 'x.panelalpha.online', 'panelalpha');
    }

    public function test_a_tunnel_of_another_project_is_not_removed(): void
    {
        $owner = $this->user('owner');
        $tunnel = $this->tunnel($owner, $this->makeMainDomain($owner, 'owner.example.test'), 'owner.panelalpha.online', Tunnel::PROVIDER_PANELALPHA);

        try {
            TunnelManager::removeTunnel($this->user('intruder'), $tunnel);
            $this->fail('a foreign tunnel must not be removed');
        } catch (CloudflareException $e) {
            $this->assertSame('Tunnel does not belong to this project.', $e->getMessage());
        }

        $this->assertTrue(Tunnel::query()->whereKey($tunnel->id)->exists());
        Http::assertNothingSent();
    }

    public function test_removing_the_last_cloudflare_tunnel_stops_the_connector(): void
    {
        $this->services->shouldReceive('configure')->once()->with('cloudflared', false, []);
        $this->services->shouldReceive('apply')->once()->with('cloudflared');
        $user = $this->user('shop');
        $tunnel = $this->tunnel($user, $this->makeMainDomain($user, 'shop.example.test'), 'www.example.test', Tunnel::PROVIDER_CLOUDFLARE);

        TunnelManager::removeTunnel($user, $tunnel);

        $this->assertSame(0, Tunnel::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_cloudflare_tunnel_left_on_the_project_keeps_the_connector(): void
    {
        $this->services->shouldNotReceive('configure');
        $user = $this->user('shop');
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $gone = $this->tunnel($user, $domain, 'www.example.test', Tunnel::PROVIDER_CLOUDFLARE);
        $this->tunnel($user, $domain, 'api.example.test', Tunnel::PROVIDER_CLOUDFLARE);

        TunnelManager::removeTunnel($user, $gone);

        $this->assertSame(['api.example.test'], Tunnel::query()->pluck('hostname')->all());
    }

    public function test_tearing_down_a_domain_releases_every_tunnel_and_drops_one_it_cannot_tear_down(): void
    {
        Log::spy();
        $this->services->shouldNotReceive('configure');
        $user = $this->user('shop');
        $stranger = $this->user('stranger');
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->tunnel($user, $domain, 'shop.panelalpha.online', Tunnel::PROVIDER_PANELALPHA);
        $this->tunnel($stranger, $domain, 'odd.panelalpha.online', Tunnel::PROVIDER_PANELALPHA);

        TunnelManager::teardownDomain($user, $domain);

        $this->assertSame(0, Tunnel::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://connect.panelalpha.test/api/without-dns/sites/shop.panelalpha.online');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'odd.panelalpha.online'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => $message === 'Tunnel teardown failed for odd.panelalpha.online: Tunnel does not belong to this project.'
        );
    }

    public function test_syncing_a_domain_without_cloudflare_tunnels_asks_cloudflare_nothing(): void
    {
        $user = $this->user('shop');
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->tunnel($user, $domain, 'shop.panelalpha.online', Tunnel::PROVIDER_PANELALPHA);

        TunnelManager::syncFromProxyRules($user, $domain);

        Http::assertNothingSent();
    }

    private function tunnel(User $user, Domain $domain, string $hostname, string $provider): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'hostname' => $hostname,
            'details' => [],
        ]);
    }

    /** A saved DinD project whose connector is the recording service manager. */
    private function user(string $username): User
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('services')->andReturn($this->services);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($dind);

        $user = new class extends User
        {
            protected $table = 'users';

            public Project $fakeProject;

            public function project(?System $system = null): Project
            {
                return $this->fakeProject;
            }
        };
        $user->fakeProject = $project;
        $user->username = $username;
        $user->domain = $username.'.example.test';
        $user->details = [];
        $user->save();

        return $user;
    }
}
