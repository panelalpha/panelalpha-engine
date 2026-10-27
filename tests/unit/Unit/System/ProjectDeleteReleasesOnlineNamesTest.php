<?php

namespace Tests\Unit\System;

use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Deleting a project dropped its tunnel rows but left its panelalpha.online
 * names registered at the proxy, each one taken out of a site quota the whole
 * engine shares. The delete gives every one back, and a proxy that refuses or
 * cannot be reached does not stop it.
 */
class ProjectDeleteReleasesOnlineNamesTest extends TestCase
{
    use InMemoryDatabase;

    private const STOP = 'stopped once the domains are reached';

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

    public function test_deleting_a_project_releases_each_of_its_panelalpha_online_names(): void
    {
        Http::fake(['connect.panelalpha.com/*' => Http::response(null, 204)]);
        $user = $this->projectOnOnlineName('rel1280', 'rel1280-565d.panelalpha.online');
        $other = $this->projectOnOnlineName('keep1280', 'keep1280-77aa.panelalpha.online');

        $this->destroyUntilTheDomains($user);

        Http::assertSentCount(2);
        $this->assertReleased('rel1280-565d.panelalpha.online');
        $this->assertReleased('api-rel1280-565d.panelalpha.online');
        $this->assertSame(0, Tunnel::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, Tunnel::query()->where('user_id', $other->id)->count());
    }

    public function test_a_proxy_that_refuses_does_not_stop_the_delete(): void
    {
        Http::fake(['connect.panelalpha.com/*' => Http::response(['message' => 'Server Error'], 500)]);
        $user = $this->projectOnOnlineName('rel1280', 'rel1280-565d.panelalpha.online');

        $this->destroyUntilTheDomains($user);

        Http::assertSentCount(2);
        $this->assertSame(0, Tunnel::query()->where('user_id', $user->id)->count());
    }

    public function test_a_proxy_that_cannot_be_reached_does_not_stop_the_delete(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to connect.panelalpha.com'));
        $user = $this->projectOnOnlineName('rel1280', 'rel1280-565d.panelalpha.online');

        $this->destroyUntilTheDomains($user);

        $this->assertSame(0, Tunnel::query()->where('user_id', $user->id)->count());
    }

    public function test_a_project_without_a_panelalpha_online_name_asks_the_proxy_nothing(): void
    {
        Http::fake();
        $user = $this->makeUser('own1280', ['template' => 'default'], 'shop.example.com');
        $this->makeMainDomain($user, 'shop.example.com');

        $this->destroyUntilTheDomains($user);

        Http::assertNothingSent();
    }

    /** A project on its own panelalpha.online name with a sibling name beside it. */
    private function projectOnOnlineName(string $username, string $fqdn): User
    {
        $user = $this->makeUser($username, ['template' => 'default'], $fqdn);
        $domain = $this->makeMainDomain($user, $fqdn);
        foreach ([$fqdn, 'api-'.$fqdn] as $hostname) {
            Tunnel::create([
                'domain_id' => $domain->id,
                'user_id' => $user->id,
                'provider' => Tunnel::PROVIDER_PANELALPHA,
                'hostname' => $hostname,
            ]);
        }

        return $user;
    }

    /** Runs the real delete up to the first step that works on the host's web server, and stops it there. */
    private function destroyUntilTheDomains(User $user): void
    {
        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('webserver')->andThrow(new RuntimeException(self::STOP));

        try {
            $system->project($user->refresh())->destroy();
            $this->fail('Expected the delete to reach the domains.');
        } catch (RuntimeException $e) {
            $this->assertSame(self::STOP, $e->getMessage());
        }
    }

    private function assertReleased(string $fqdn): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/'.$fqdn);
    }
}
