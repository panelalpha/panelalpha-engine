<?php

namespace Tests\Unit\Domains;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\MainDomainRename;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A main-domain rename and PanelAlpha Online: a name under panelalpha.online is
 * refused, the old name's own site on the proxy is deleted with it, and any other
 * tunnel has to be deleted first rather than dropped with the old row.
 */
class MainDomainRenameTunnelTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Setting::setRuntimeSettings(['license_key' => 'K', 'default_ipv4' => TestNetwork::public(10)]);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        Http::fake(['connect.panelalpha.com/*' => Http::response(null, 204)]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Domain::flushEventListeners();
        parent::tearDown();
    }

    public function test_a_name_under_panelalpha_online_is_refused_and_nothing_changes(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress', 'domain' => MainDomainRename::allocationFor('old.test')], 'old.test');
        $this->makeMainDomain($alice, 'old.test');

        foreach (['shop.panelalpha.online', 'Shop.PanelAlpha.Online', 'a.b.panelalpha.online'] as $name) {
            $this->putJson('/api/projects/alice', ['domain' => $name])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.field', 'domain')
                ->assertJsonPath('problems.0.code', 'domain_proxy_zone');
        }

        $alice = User::findByUsernameOrFail('alice');
        $this->assertSame('old.test', $alice->getMainDomain()?->domain);
        $this->assertSame('engine', $alice->getDetails()['domain']['tls_terminated_at']);
        Http::assertNothingSent();
    }

    public function test_a_main_domain_with_another_tunnel_is_refused_until_it_is_deleted(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'shop.panelalpha.online');
        $main = $this->makeMainDomain($alice, 'shop.panelalpha.online');
        $this->tunnel($main, 'shop.panelalpha.online');
        $this->tunnel($main, 'api-shop.panelalpha.online');
        $this->tunnel($main, 'app.example.com', Tunnel::PROVIDER_CLOUDFLARE);

        $this->putJson('/api/projects/alice', ['domain' => 'shop.example.com'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'domain_has_tunnels')
            ->assertJsonPath('problems.0.message', 'shop.panelalpha.online has tunnels that forward to it (api-shop.panelalpha.online, app.example.com); delete them before renaming it.');

        $this->assertSame(3, Tunnel::query()->count());
        $this->assertSame('shop.panelalpha.online', User::findByUsernameOrFail('alice')->getMainDomain()?->domain);
    }

    public function test_the_tunnel_that_is_the_old_name_does_not_refuse_the_rename(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'shop.panelalpha.online');
        $this->tunnel($this->makeMainDomain($alice, 'shop.panelalpha.online'), 'shop.panelalpha.online');

        $this->assertNull(MainDomainRename::refusal($alice, 'shop.example.com'));
        $this->assertNull(MainDomainRename::refusal($alice, 'SHOP.panelalpha.online'), 'the same name is not a rename');
    }

    public function test_renaming_away_from_a_panelalpha_online_name_deletes_its_site_on_the_proxy(): void
    {
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'shop.panelalpha.online');
        $this->tunnel($this->makeMainDomain($alice, 'shop.panelalpha.online'), 'shop.panelalpha.online');
        $this->hostWorkSucceeds($alice);

        MainDomainRename::apply($alice, 'shop.example.com');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/shop.panelalpha.online');
        $this->assertSame(0, Tunnel::query()->count());
        $this->assertSame(['shop.example.com'], Domain::query()->pluck('domain')->all());
        $this->assertSame('engine', $alice->getDetails()['domain']['tls_terminated_at']);
    }

    public function test_a_proxy_that_cannot_be_reached_does_not_undo_the_rename(): void
    {
        Http::fake(['connect.panelalpha.com/*' => Http::failedConnection('connect.panelalpha.com is unreachable')]);
        $alice = $this->makeUser('alice', ['template' => 'wordpress'], 'shop.panelalpha.online');
        $this->tunnel($this->makeMainDomain($alice, 'shop.panelalpha.online'), 'shop.panelalpha.online');
        $this->hostWorkSucceeds($alice);
        Log::spy();

        MainDomainRename::apply($alice, 'shop.example.com');

        $this->assertSame('shop.example.com', $alice->domain);
        $this->assertSame('shop.example.com', User::query()->whereKey($alice->getKey())->value('domain'));
        $this->assertSame(['shop.example.com'], Domain::query()->pluck('domain')->all());
        $this->assertSame(0, Tunnel::query()->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'shop.panelalpha.online')
                && str_contains($message, 'connect.panelalpha.com is unreachable')
        );
    }

    private function tunnel(Domain $domain, string $hostname, string $provider = Tunnel::PROVIDER_PANELALPHA): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $domain->user_id,
            'provider' => $provider,
            'hostname' => $hostname,
        ]);
    }

    /** Every domain row read from here on renders its vhost through a project whose host work does nothing. */
    private function hostWorkSucceeds(User $user): void
    {
        $projectDomain = Mockery::mock(ProjectDomain::class);
        $projectDomain->allows('create', 'delete');
        $project = Mockery::mock(Project::class);
        $project->allows('domain')->andReturn($projectDomain);
        $owner = new class ($project) extends User {
            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $owner->setRawAttributes($user->getAttributes(), true);
        $owner->exists = true;
        Domain::retrieved(static fn (Domain $domain) => $domain->setRelation('user', $owner));
    }
}
