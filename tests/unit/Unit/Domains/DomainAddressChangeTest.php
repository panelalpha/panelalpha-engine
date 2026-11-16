<?php

namespace Tests\Unit\Domains;

use App\Exceptions\ProblemException;
use App\Lib\Domains\DomainAddressChange;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Domains\MainDomainSwitch;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Moving a project to another of its domains or ports: the old one is kept until it is deleted, and the project is
 * redeployed only when the address its app is told changes.
 */
class DomainAddressChangeTest extends TestCase
{
    use InMemoryDatabase;

    private int $redeploys = 0;

    /** @var list<array{string, list<int>, int}> */
    private array $portChanges = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => 'host.example.test']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_an_addon_becomes_the_main_domain_and_the_old_one_stays_an_addon(): void
    {
        $user = $this->project('shop', deployed: true);
        $addon = $this->addon($user, 'shop.example.com');

        $task = $this->change()->apply($user, $addon, ['main' => true]);

        $user = User::findByUsernameOrFail('shop');
        $this->assertSame('shop.example.com', $user->domain);
        $this->assertSame('shop.example.com', $user->getMainDomain()?->domain);
        $this->assertSame(['shop.example.com' => 'main', 'shop.example.test' => 'addon'], Domain::query()->orderBy('domain')->pluck('type', 'domain')->all());
        $this->assertSame('requested', $user->getDetails()['domain']['source']);
        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame(1, $this->redeploys);
    }

    public function test_a_project_never_deployed_is_not_redeployed(): void
    {
        $user = $this->project('shop', deployed: false);

        $this->assertNull($this->change()->apply($user, $this->addon($user, 'shop.example.com'), ['main' => true]));
        $this->assertSame('shop.example.com', User::findByUsernameOrFail('shop')->domain);
        $this->assertSame(0, $this->redeploys);
    }

    public function test_only_an_addon_of_a_container_project_becomes_main(): void
    {
        $classic = $this->project('blog', deployed: true, template: 'wordpress');
        $this->assertRefused('main', MainDomainSwitch::CONTAINER_ONLY, fn () => $this->change()->apply($classic, $this->addon($classic, 'blog.example.com'), ['main' => true]));

        $user = $this->project('shop', deployed: true);
        $sub = $this->addon($user, 'api.shop.example.test', 'sub');
        $this->assertRefused('main', MainDomainSwitch::NOT_ADDON, fn () => $this->change()->apply($user, $sub, ['main' => true]));
        $this->assertSame('shop.example.test', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_the_servers_own_name_becomes_main_only_with_the_operators_permission(): void
    {
        $user = $this->project('panel', deployed: true);
        $host = $this->addon($user, 'host.example.test');

        $this->assertRefused('main', 'host_domain_not_allowed', fn () => $this->change()->apply($user, $host, ['main' => true]));

        (new PublicRoutingGrants)->allow('panel', [PublicRoutingGrants::HOST_DOMAIN]);
        $this->change()->apply($user, $host, ['main' => true]);
        $this->assertSame('host.example.test', User::findByUsernameOrFail('panel')->domain);
    }

    public function test_moving_the_main_domains_url_to_another_port_redeploys(): void
    {
        $user = $this->project('shop', deployed: true);
        $main = $user->getMainDomain();

        $this->assertNull($this->change()->apply($user, $main, ['https_ports' => [443, 8443]]), 'adding a port leaves the URL alone');
        $this->assertNotNull($this->change()->apply($user, $main->refresh(), ['https_port' => 8443]));
        $this->assertNull($this->change()->apply($user, $main->refresh(), ['https_ports' => [8443]]), 'dropping 443 once 8443 is main');

        $this->assertSame([['shop.example.test', [443, 8443], 443], ['shop.example.test', [443, 8443], 8443], ['shop.example.test', [8443], 8443]], $this->portChanges);
        $this->assertSame(1, $this->redeploys);
    }

    public function test_an_addons_ports_never_redeploy(): void
    {
        $user = $this->project('shop', deployed: true);

        $this->change()->apply($user, $this->addon($user, 'shop.example.com'), ['https_ports' => [8443], 'https_port' => 8443]);

        $this->assertSame(0, $this->redeploys);
    }

    public function test_the_main_port_is_not_removed_until_another_is_main(): void
    {
        $user = $this->project('shop', deployed: true);

        $this->assertRefused(HttpsPorts::PORTS_KEY, DomainAddressChange::MAIN_PORT_REMOVED, fn () => $this->change()->apply($user, $user->getMainDomain(), ['https_ports' => [8443]]));
        $this->assertSame([], $this->portChanges);
    }

    private function change(): DomainAddressChange
    {
        $ports = new class (Mockery::mock(System::class), new PublicRoutingGrants, $this) extends HttpsPortsChange {
            public function __construct(System $system, PublicRoutingGrants $grants, private DomainAddressChangeTest $test)
            {
                parent::__construct($system, $grants);
            }

            public function refusal(Domain $domain, array $ports, int $main): ?array
            {
                return null;
            }

            public function apply(Domain $domain, array $ports, int $main): void
            {
                $this->test->recordPorts($domain, $ports, $main);
            }
        };
        $rebuild = Mockery::mock(ProjectRebuild::class);
        $rebuild->allows('whileIdle')->andReturnUsing(static fn (User $user, callable $start): mixed => $start());
        $rebuild->allows('queue')->andReturnUsing(function (): Task {
            $this->redeploys++;

            return new Task;
        });

        return new DomainAddressChange($ports, $rebuild);
    }

    /** @param list<int> $ports */
    public function recordPorts(Domain $domain, array $ports, int $main): void
    {
        $this->portChanges[] = [(string) $domain->domain, $ports, $main];
        $domain->setDetails([HttpsPorts::PORTS_KEY => $ports, HttpsPorts::MAIN_KEY => $main]);
        $domain->save();
    }

    private function project(string $username, bool $deployed, string $template = 'dind'): User
    {
        $user = $this->makeUser($username, ['template' => $template] + ($deployed ? ['app_port' => 8000] : []), "{$username}.example.test");
        $this->makeMainDomain($user, "{$username}.example.test");

        return $user;
    }

    private function addon(User $user, string $name, string $type = 'addon'): Domain
    {
        /** @var Domain */
        return Domain::query()->create(['user_id' => $user->id, 'domain' => $name, 'type' => $type, 'details' => ['document_root' => "/{$name}/public_html"]]);
    }

    private function assertRefused(string $field, string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code}");
        } catch (ProblemException $e) {
            $this->assertSame([$field, $code], [$e->problems[0]['field'], $e->problems[0]['code']]);
        }
    }
}
