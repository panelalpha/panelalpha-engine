<?php

namespace Tests\Integration\Project\PublicRouting;

use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `domain: host` moving a project onto the server's own name, and the operator's refusal moving it off again:
 * back to the name it had, or a fresh one, with every addon and alias of the server's name gone.
 */
class HostDomainMovesTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    private DomainAllocator&MockInterface $allocator;

    private ProjectDomain&MockInterface $vhost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => self::HOST]);
        $this->allocator = Mockery::mock(DomainAllocator::class);
        $this->vhost = Mockery::mock(ProjectDomain::class);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Domain::flushEventListeners();
        parent::tearDown();
    }

    public function test_a_file_that_does_not_ask_leaves_the_project_on_its_generated_name(): void
    {
        $user = $this->project('shop', 'shop.example.test');

        $state = $this->hostDomain()->apply($user, PublicRouting::none(), null);

        $this->assertSame(['state' => HostDomain::GENERATED, 'name' => 'shop.example.test'], $state);
    }

    public function test_an_engine_without_a_name_refuses_domain_host(): void
    {
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => '']);
        $this->allow('shop');
        $user = $this->project('shop', 'shop.example.test');

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), null);

        $this->assertSame(HostDomain::REFUSED, $state['state']);
        $this->assertSame('the engine has no name of its own: cert_domain is not set', $state['reason']);
    }

    public function test_a_project_already_on_the_name_is_reported_on_it(): void
    {
        $this->allow('shop');
        $user = $this->project('shop', self::HOST);

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), null);

        $this->assertSame(['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'applied' => true], $state);
    }

    public function test_a_name_another_project_holds_is_refused(): void
    {
        $this->allow('shop');
        $user = $this->project('shop', 'shop.example.test');
        $this->makeUser('other', ['template' => 'dind'], self::HOST);

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), null);

        $this->assertSame(HostDomain::REFUSED, $state['state']);
        $this->assertSame(self::HOST.' is already on this engine.', $state['reason']);
        $this->assertSame('shop.example.test', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_an_allowed_project_is_moved_onto_the_name_and_remembers_its_own(): void
    {
        $this->allow('shop');
        $allocation = ['source' => DomainPlan::SOURCE_REQUESTED, 'fqdn' => 'shop.example.test'];
        $user = $this->project('shop', 'shop.example.test', ['domain' => $allocation]);
        $this->vhost->expects('create')->once();
        $this->vhost->expects('delete')->once();

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), null);

        $this->assertSame(HostDomain::ON_HOST, $state['state']);
        $this->assertTrue($state['applied']);
        $this->assertSame(['name' => 'shop.example.test', 'allocation' => $allocation], $state['previous']);
        $this->assertSame(self::HOST, User::findByUsernameOrFail('shop')->domain);
        $this->assertSame([self::HOST], Domain::query()->pluck('domain')->all());
    }

    public function test_a_project_on_the_name_without_permission_goes_back_to_its_own(): void
    {
        $user = $this->project('shop', self::HOST);
        $previous = ['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'previous' => ['name' => 'shop.example.test', 'allocation' => ['source' => 'kept']]];
        $this->vhost->expects('create')->once();
        $this->vhost->expects('delete')->once();

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $previous);

        $this->assertSame(HostDomain::NOT_ALLOWED, $state['state']);
        $this->assertTrue($state['applied']);
        $this->assertArrayNotHasKey('previous', $state);
        $fresh = User::findByUsernameOrFail('shop');
        $this->assertSame('shop.example.test', $fresh->domain);
        $this->assertSame(['source' => 'kept'], $fresh->getDetails()['domain']);
    }

    public function test_a_released_project_with_no_name_of_its_own_gets_a_fresh_one(): void
    {
        $user = $this->project('shop', self::HOST);
        $this->allocator->expects('allocate')->with('shop', null, DomainPlan::TUNNEL_NONE)
            ->andReturn(new AllocatedDomain('shop-new.example.test', DomainPlan::SOURCE_REQUESTED));
        $this->vhost->expects('create')->once();
        $this->vhost->expects('delete')->once();

        $state = $this->hostDomain()->release($user, ['state' => HostDomain::ON_HOST, 'name' => self::HOST], HostDomain::NOT_ALLOWED, 'taken away');

        $this->assertSame(['state' => HostDomain::NOT_ALLOWED, 'name' => 'shop-new.example.test', 'reason' => 'taken away', 'applied' => true], $state);
        $this->assertSame('shop-new.example.test', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_an_addon_on_the_name_is_deleted_and_its_https_ports_closed(): void
    {
        $user = $this->project('shop', 'shop.example.test');
        $addon = $this->makeMainDomain($user, self::HOST);
        $addon->forceFill(['type' => 'addon']);
        $addon->setDetails([HttpsPorts::PORTS_KEY => [443, 8443]]);
        $addon->save();
        $httpsPorts = Mockery::mock(HttpsPortsChange::class);
        $httpsPorts->expects('listenersChanged')->once();
        $this->app->instance(HttpsPortsChange::class, $httpsPorts);
        $this->vhost->expects('delete')->once();

        $state = $this->hostDomain()->apply($user, PublicRouting::none(), null);

        $this->assertSame(HostDomain::NOT_ALLOWED, $state['state']);
        $this->assertSame(['shop.example.test'], Domain::query()->pluck('domain')->all());
        $this->assertSame('shop.example.test', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_the_name_is_taken_off_an_alias_list(): void
    {
        $user = $this->project('shop', 'shop.example.test');
        $main = $user->getMainDomain();
        $main->setDetails(['aliases' => ['www.shop.example.test', 'HOST.example.test']]);
        $main->save();
        $this->vhost->expects('rebuild')->once();

        $state = $this->hostDomain()->apply($user, PublicRouting::none(), null);

        $this->assertSame(HostDomain::NOT_ALLOWED, $state['state']);
        $this->assertSame(['www.shop.example.test'], Domain::query()->firstOrFail()->getAliases());
    }

    public function test_a_project_that_holds_nothing_of_the_name_is_left_alone(): void
    {
        $user = $this->project('shop', 'shop.example.test');
        $main = $user->getMainDomain();
        $main->setDetails(['aliases' => ['www.shop.example.test']]);
        $main->save();

        $state = $this->hostDomain()->apply($user, PublicRouting::none(), null);

        $this->assertSame(HostDomain::GENERATED, $state['state']);
        $this->assertSame(['www.shop.example.test'], Domain::query()->firstOrFail()->getAliases());
    }

    public function test_the_reported_state_is_kept_while_the_project_has_not_moved(): void
    {
        $user = $this->project('shop', self::HOST);

        $current = HostDomain::current($user, ['state' => HostDomain::ON_HOST, 'name' => 'stale.example.test', 'reason' => 'kept']);

        $this->assertSame(['name' => self::HOST, 'state' => HostDomain::ON_HOST, 'reason' => 'kept'], $current);
    }

    private function hostDomain(): HostDomain
    {
        return new HostDomain(new PublicRoutingGrants, $this->allocator);
    }

    private function allow(string $username): void
    {
        (new PublicRoutingGrants)->allow($username, [PublicRoutingGrants::HOST_DOMAIN]);
    }

    /** @param array<string, mixed> $details */
    private function project(string $username, string $domain, array $details = []): User
    {
        $user = $this->makeUser($username, ['template' => 'wordpress'] + $details, $domain);
        $this->makeMainDomain($user, $domain);
        $this->hostWorkGoesTo($user);

        return $user;
    }

    /** Every domain row read from here on renders its vhost through the recording vhost mock. */
    private function hostWorkGoesTo(User $user): void
    {
        $project = Mockery::mock(Project::class);
        $project->allows('domain')->andReturn($this->vhost);
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
