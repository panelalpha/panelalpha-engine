<?php

namespace Tests\Unit\Project\PublicRouting;

use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\MainDomainRename;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Setting;
use App\Models\User;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The server's own name: held only with the operator's permission, and taken by `domain: host` once.
 */
class HostDomainTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => self::HOST]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_the_servers_name_needs_the_operators_permission(): void
    {
        $refusal = HostDomain::claimRefusal('shop', self::HOST);

        $this->assertSame(HostDomain::CLAIM_REFUSED, $refusal['code'] ?? null);
        $this->assertStringNotContainsString('pae ', (string) ($refusal['message'] ?? ''), 'a refusal names no surface');
        $this->assertNotNull(HostDomain::claimRefusal('shop', 'HOST.example.test.'));
        $this->assertNull(HostDomain::claimRefusal('shop', 'shop.example.test'));

        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::HOST_DOMAIN]);
        $this->assertNull(HostDomain::claimRefusal('shop', self::HOST));
    }

    public function test_an_engine_without_a_name_of_its_own_refuses_nothing(): void
    {
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => '']);

        $this->assertNull(HostDomain::claimRefusal('shop', self::HOST));
    }

    public function test_a_rename_to_the_servers_name_is_refused_without_permission(): void
    {
        $user = $this->project('shop', 'shop.example.test');

        $this->assertSame(HostDomain::CLAIM_REFUSED, MainDomainRename::refusal($user, self::HOST)['code'] ?? null);
    }

    public function test_domain_host_is_not_applied_again_once_the_project_moved_off_the_name(): void
    {
        (new PublicRoutingGrants)->allow('panel', [PublicRoutingGrants::HOST_DOMAIN]);
        $user = $this->project('panel', 'panel.example.com');
        $previous = ['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'applied' => true, 'previous' => ['name' => 'panel.example.test', 'allocation' => null]];

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $previous);

        $this->assertSame([HostDomain::GENERATED, 'panel.example.com', true], [$state['state'], $state['name'], $state['applied'] ?? false]);
        $this->assertSame('panel.example.com', User::findByUsernameOrFail('panel')->domain);
    }

    public function test_a_file_that_stops_asking_does_not_move_the_project_back(): void
    {
        (new PublicRoutingGrants)->allow('panel', [PublicRoutingGrants::HOST_DOMAIN]);
        $user = $this->project('panel', self::HOST);
        $previous = ['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'previous' => ['name' => 'panel.example.test', 'allocation' => null]];

        $state = $this->hostDomain()->apply($user, PublicRouting::none(), $previous);

        $this->assertSame(HostDomain::ON_HOST, $state['state']);
        $this->assertTrue($state['applied'] ?? false, 'a project recorded on the name counts as applied');
        $this->assertSame(self::HOST, User::findByUsernameOrFail('panel')->domain);
    }

    public function test_without_permission_domain_host_is_only_reported(): void
    {
        $user = $this->project('panel', 'panel.example.test');

        $state = $this->hostDomain()->apply($user, new PublicRouting(PublicRouting::DOMAIN_HOST), null);

        $this->assertSame(HostDomain::NOT_ALLOWED, $state['state']);
        $this->assertArrayNotHasKey('applied', $state);
        $this->assertSame('panel.example.test', User::findByUsernameOrFail('panel')->domain);
    }

    public function test_the_reported_state_follows_the_domains_the_project_has_now(): void
    {
        $user = $this->project('panel', 'panel.example.com');

        $current = HostDomain::current($user, ['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'applied' => true]);

        $this->assertSame([HostDomain::GENERATED, 'panel.example.com', true], [$current['state'], $current['name'], $current['applied']]);
        $this->assertNull(HostDomain::current($user, null));
    }

    private function hostDomain(): HostDomain
    {
        return new HostDomain(new PublicRoutingGrants, Mockery::mock(DomainAllocator::class));
    }

    private function project(string $username, string $domain): User
    {
        $user = $this->makeUser($username, ['template' => 'dind'], $domain);
        $this->makeMainDomain($user, $domain);

        return $user;
    }
}
