<?php

namespace Tests\Integration\Project\PublicRouting;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicPort;
use App\Lib\Project\PublicRouting\PublicPortRules;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A project's `domain:` and `public_ports:` applied: what came of it is recorded on the project, logged to its deploy,
 * shown on its resource, and taken back when the operator denies it.
 */
class ProjectPublicRoutingTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    private HostDomain&MockInterface $hostDomain;

    private PublicPortRules&MockInterface $ports;

    private HttpsPortsChange&MockInterface $httpsPorts;

    /** @var list<array{string, string}> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => self::HOST]);
        $this->hostDomain = Mockery::mock(HostDomain::class);
        $this->ports = Mockery::mock(PublicPortRules::class);
        $this->httpsPorts = Mockery::mock(HttpsPortsChange::class);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_project_that_asked_for_nothing_has_no_view(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);

        $this->assertNull(ProjectPublicRouting::view($user, new PublicRoutingGrants));
    }

    public function test_the_view_shows_what_was_asked_allowed_and_where_the_project_is(): void
    {
        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::PORTS]);
        $port = ['listen' => 2222, 'to' => 22, 'transport' => PublicPort::TCP, 'state' => PublicPortRules::OPEN];
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => [
            'declared' => (new PublicRouting(PublicRouting::DOMAIN_HOST, [new PublicPort(2222, 22)]))->toArray(),
            'domain' => ['state' => HostDomain::GENERATED, 'name' => 'shop.example.test', 'previous' => ['name' => 'old.example.test', 'allocation' => null]],
            'ports' => [$port],
        ]], 'shop.example.test');

        $view = ProjectPublicRouting::view($user, new PublicRoutingGrants);

        $this->assertSame([PublicRoutingGrants::PORTS], $view['allowed']);
        $this->assertSame(PublicRouting::DOMAIN_HOST, $view['asked']['domain']);
        $this->assertSame(['name' => 'shop.example.test', 'state' => HostDomain::GENERATED], $view['domain']);
        $this->assertSame([$port], $view['ports']);
    }

    public function test_a_project_recorded_on_the_host_without_a_domain_state_shows_no_domain(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => [
            'ports' => [['listen' => 2222, 'to' => 22, 'transport' => PublicPort::TCP, 'state' => PublicPortRules::OPEN]],
        ]]);

        $view = ProjectPublicRouting::view($user, new PublicRoutingGrants);

        $this->assertNull($view['domain']);
        $this->assertSame([], $view['allowed']);
    }

    public function test_prepare_does_nothing_for_a_project_that_never_asked(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->hostDomain->shouldNotReceive('apply');

        $this->routing()->prepare($user, PublicRouting::none(), $this->logger());

        $this->assertSame([], ProjectPublicRouting::recorded(User::findByUsernameOrFail('shop')));
        $this->assertSame([], $this->lines);
    }

    public function test_prepare_records_the_domain_and_logs_a_move_onto_the_host(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $routing = new PublicRouting(PublicRouting::DOMAIN_HOST);
        $this->hostDomain->shouldReceive('apply')->once()->with($user, $routing, null)
            ->andReturn(['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'applied' => true]);

        $this->routing()->prepare($user, $routing, $this->logger());

        $recorded = ProjectPublicRouting::recorded(User::findByUsernameOrFail('shop'));
        $this->assertSame($routing->toArray(), $recorded['declared']);
        $this->assertSame(HostDomain::ON_HOST, $recorded['domain']['state']);
        $this->assertSame([['info', "Serving the project on the server's own name ".self::HOST.' (domain: host)']], $this->lines);
    }

    public function test_a_project_already_on_the_host_is_not_announced_as_moved(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => [
            'domain' => ['state' => HostDomain::ON_HOST, 'name' => self::HOST],
        ]]);
        $this->hostDomain->shouldReceive('apply')->once()->andReturn(['state' => HostDomain::ON_HOST, 'name' => self::HOST, 'applied' => true]);

        $this->routing()->prepare($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $this->logger());

        $this->assertSame([['info', "Serving the project on the server's own name ".self::HOST]], $this->lines);
    }

    public function test_a_refused_host_domain_is_a_warning_naming_the_reason(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->hostDomain->shouldReceive('apply')->once()
            ->andReturn(['state' => HostDomain::REFUSED, 'name' => 'shop.example.test', 'reason' => 'cert_domain is not set.']);

        $this->routing()->prepare($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $this->logger());

        $this->assertSame([['warn', 'domain: host was not applied: cert_domain is not set; the project stays on shop.example.test']], $this->lines);
    }

    public function test_a_host_domain_not_allowed_while_asked_for_is_a_warning(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->hostDomain->shouldReceive('apply')->once()
            ->andReturn(['state' => HostDomain::NOT_ALLOWED, 'name' => 'shop.example.test', 'reason' => 'not allowed']);

        $this->routing()->prepare($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $this->logger());

        $this->assertSame([['warn', 'domain: host was not applied: not allowed; the project stays on shop.example.test']], $this->lines);
    }

    public function test_a_project_moved_off_the_host_says_where_it_went(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => [
            'domain' => ['state' => HostDomain::ON_HOST, 'name' => self::HOST],
        ]]);
        $this->hostDomain->shouldReceive('apply')->once()
            ->andReturn(['state' => HostDomain::NOT_ALLOWED, 'name' => 'shop.example.test', 'reason' => 'the operator said no.']);

        $this->routing()->prepare($user, PublicRouting::none(), $this->logger());

        $this->assertSame([['info', 'Moved off the server\'s own name to shop.example.test: the operator said no']], $this->lines);
    }

    public function test_an_earlier_applied_host_domain_is_not_moved_back(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->hostDomain->shouldReceive('apply')->once()
            ->andReturn(['state' => HostDomain::GENERATED, 'name' => 'shop.example.test', 'applied' => true]);

        $this->routing()->prepare($user, new PublicRouting(PublicRouting::DOMAIN_HOST), $this->logger());

        $this->assertSame([['info', 'domain: host was applied at an earlier deploy; the project is on shop.example.test now, and the file does not move it back']], $this->lines);
    }

    public function test_route_ports_records_and_logs_each_port(): void
    {
        $declared = new PublicRouting(PublicRouting::DOMAIN_GENERATED, [new PublicPort(2222, 22), new PublicPort(5353, 53, PublicPort::UDP)]);
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => ['declared' => $declared->toArray()]]);
        $states = [
            ['listen' => 2222, 'to' => 22, 'transport' => PublicPort::TCP, 'state' => PublicPortRules::OPEN],
            ['listen' => 5353, 'to' => 53, 'transport' => PublicPort::UDP, 'state' => PublicPortRules::REFUSED, 'reason' => 'the port is taken'],
        ];
        $this->ports->shouldReceive('apply')->once()
            ->withArgs(static fn (string $username, PublicRouting $routing): bool => $username === 'shop' && $routing->toArray() === $declared->toArray())
            ->andReturn($states);

        $this->routing()->routePorts($user, $this->logger());

        $this->assertSame($states, ProjectPublicRouting::recorded(User::findByUsernameOrFail('shop'))['ports']);
        $this->assertSame([
            ['info', 'Public port tcp 2222 → 22 is open'],
            ['warn', 'Public port udp 5353 → 53 was not opened: the port is taken'],
        ], $this->lines);
    }

    public function test_route_ports_touches_nothing_without_ports_declared_or_recorded(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->ports->shouldNotReceive('apply');

        $this->routing()->routePorts($user, $this->logger());

        $this->assertSame([], $this->lines);
    }

    public function test_allowing_ports_opens_them_from_the_last_deploy(): void
    {
        $declared = new PublicRouting(PublicRouting::DOMAIN_GENERATED, [new PublicPort(2222, 22)]);
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => ['declared' => $declared->toArray()]]);
        $open = [['listen' => 2222, 'to' => 22, 'transport' => PublicPort::TCP, 'state' => PublicPortRules::OPEN]];
        $this->ports->shouldReceive('apply')->once()->andReturn($open);

        $result = $this->routing()->allow($user, [PublicRoutingGrants::PORTS]);

        $this->assertSame(['allowed' => [PublicRoutingGrants::PORTS], 'ports' => $open], $result);
    }

    public function test_denying_ports_puts_every_domain_back_on_443(): void
    {
        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::PORTS]);
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $main = $this->makeMainDomain($user, 'shop.example.test');
        $main->setDetails([HttpsPorts::PORTS_KEY => [443, 8443]]);
        $main->save();
        $this->makeMainDomain($user, 'plain.example.test')->forceFill(['type' => 'addon'])->save();
        $this->httpsPorts->shouldReceive('apply')->once()
            ->withArgs(static fn (Domain $domain, array $ports, int $mainPort): bool => $domain->domain === 'shop.example.test'
                && $ports === [HttpsPorts::DEFAULT] && $mainPort === HttpsPorts::DEFAULT)
            ->andReturnUsing(static function (Domain $domain): void {
                $domain->setDetails([HttpsPorts::PORTS_KEY => [HttpsPorts::DEFAULT]]);
                $domain->save();
            });

        $result = $this->routing()->deny($user, [PublicRoutingGrants::PORTS]);

        $this->assertSame(['shop.example.test'], $result['https_closed']);
        $this->assertTrue($result['changed']);
        $this->assertNull($result['moved_to']);
        $this->assertSame([], $result['allowed']);
    }

    public function test_declared_ports_are_published_and_unbound_ones_warned_about(): void
    {
        $declared = new PublicRouting(PublicRouting::DOMAIN_GENERATED, [new PublicPort(2222, 22)]);
        $user = $this->makeUser('shop', ['template' => 'dind', ProjectPublicRouting::DETAIL => ['declared' => $declared->toArray()]]);

        $published = ProjectPublicRouting::publishDeclaredPorts($user, ['services' => ['web' => ['image' => 'nginx', 'expose' => ['22']]]], $this->logger());
        $unbound = ProjectPublicRouting::publishDeclaredPorts($user, ['services' => ['db' => ['image' => 'postgres']]], $this->logger());

        $this->assertSame(['22:22'], $published['services']['web']['ports']);
        $this->assertSame(['services' => ['db' => ['image' => 'postgres']]], $unbound);
        $this->assertSame([
            ['info', 'Published tcp 22 on web for public_ports'],
            ['warn', 'No service to publish tcp 22 on for public_ports: none offers or publishes a port'],
        ], $this->lines);
    }

    public function test_a_project_without_declared_ports_keeps_its_run_file(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $compose = ['services' => ['web' => ['image' => 'nginx']]];

        $this->assertSame($compose, ProjectPublicRouting::publishDeclaredPorts($user, $compose, $this->logger()));
        $this->assertSame([], $this->lines);
    }

    private function routing(): ProjectPublicRouting
    {
        return new ProjectPublicRouting($this->hostDomain, $this->ports, new PublicRoutingGrants, $this->httpsPorts);
    }

    private function logger(): DeployLogger
    {
        $logger = Mockery::mock(DeployLogger::class);
        $logger->allows('info')->andReturnUsing(function (string $message): void {
            $this->lines[] = ['info', $message];
        });
        $logger->allows('warn')->andReturnUsing(function (string $message): void {
            $this->lines[] = ['warn', $message];
        });

        return $logger;
    }
}
