<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\System;
use App\System\Project\Dind\Generation\RouteSwitch;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * engine#33: the switch to the second generation and back is the account's
 * own HTTP proxy rules moving between ports, then one graceful reload.
 */
class RouteSwitchTest extends TestCase
{
    /** @var list<string> */
    private array $rebuilt = [];

    private int $reloads = 0;

    private int $fullRebuilds = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('proxy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('owner_scope')->default('user');
            $table->string('username')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('transport');
            $table->string('listen_ip')->nullable()->default('*');
            $table->unsignedInteger('listen_port');
            $table->string('server_name')->nullable();
            $table->string('upstream_host');
            $table->unsignedInteger('upstream_port');
            $table->string('upstream_protocol')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('domain')->index();
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('domain_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->string('provider')->default('cloudflare');
            $table->string('hostname');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tunnels');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    public function test_the_site_moves_to_the_new_port_and_back(): void
    {
        $this->domain('acme.example.test');
        $http = $this->rule('acme', 'acme.example.test', 80, 3000);
        $https = $this->rule('acme', 'acme.example.test', 443, 3000);
        $admin = $this->rule('acme', 'acme.example.test', 8443, 8081);
        $neighbour = $this->rule('other', 'other.example.test', 443, 3000);
        $switch = new RouteSwitch($this->system(), 'acme');

        $moved = $switch->move([3000 => 32771]);

        $this->assertEqualsCanonicalizing([$http, $https], $moved);
        $this->assertSame(32771, ProxyRule::find($https)->upstream_port);
        $this->assertSame(8081, ProxyRule::find($admin)->upstream_port, 'a port the swap does not move');
        $this->assertSame(3000, ProxyRule::find($neighbour)->upstream_port, 'another account');
        $this->assertSame(['acme.example.test'], $this->rebuilt);
        $this->assertSame(1, $this->reloads);

        $switch->move([32771 => 3000], $moved);

        $this->assertSame(3000, ProxyRule::find($http)->upstream_port);
        $this->assertSame(3000, ProxyRule::find($https)->upstream_port);
        $this->assertSame(2, $this->reloads);
    }

    public function test_moving_back_touches_only_the_rules_it_moved(): void
    {
        $this->domain('acme.example.test');
        $moved = $this->rule('acme', 'acme.example.test', 443, 32771);
        $operator = $this->rule('acme', 'acme.example.test', 8443, 32771);

        (new RouteSwitch($this->system(), 'acme'))->move([32771 => 3000], [$moved]);

        $this->assertSame(3000, ProxyRule::find($moved)->upstream_port);
        $this->assertSame(32771, ProxyRule::find($operator)->upstream_port);
    }

    public function test_nothing_routed_means_no_reload(): void
    {
        $this->assertSame([], (new RouteSwitch($this->system(), 'acme'))->move([3000 => 32771]));
        $this->assertSame(0, $this->reloads);
    }

    public function test_rules_of_every_transport_and_tunnels_are_found(): void
    {
        $domainId = $this->domain('acme.example.test');
        $this->rule('acme', 'acme.example.test', 443, 3000);
        $this->operatorRule('acme', 'tcp', 25565, 3000);
        $switch = new RouteSwitch($this->system(), 'acme');

        $this->assertCount(2, $switch->rulesTo([3000]));
        $this->assertSame([], $switch->rulesTo([8080]));
        $this->assertNull(RouteSwitch::tunnelledDomain($switch->httpRulesTo([3000])));

        // A PanelAlpha Online name points DNS at this host: the vhost still routes it.
        DB::table('tunnels')->insert(['domain_id' => $domainId, 'provider' => 'panelalpha', 'hostname' => 'acme-1a2b.panelalpha.online']);
        $this->assertNull(RouteSwitch::tunnelledDomain($switch->httpRulesTo([3000])));

        DB::table('tunnels')->insert(['domain_id' => $domainId, 'provider' => 'cloudflare', 'hostname' => 'acme.example.test']);
        $this->assertSame('acme.example.test', RouteSwitch::tunnelledDomain($switch->httpRulesTo([3000])));
    }

    /** engine#691: an operator's own rules to the app's port go with the switch, and nginx sees it. */
    public function test_an_operators_rules_to_the_app_port_switch_with_the_site(): void
    {
        $this->domain('acme.example.test');
        $site = $this->rule('acme', 'acme.example.test', 443, 3000);
        $http = $this->operatorRule('acme', 'http', 18691, 3000);
        $tcp = $this->operatorRule('acme', 'tcp', 25691, 3000);
        $switch = new RouteSwitch($this->system(), 'acme');

        $moved = $switch->move([3000 => 32771]);

        $this->assertEqualsCanonicalizing([$site, $http, $tcp], $moved);
        $this->assertSame(32771, ProxyRule::find($http)->upstream_port);
        $this->assertSame(32771, ProxyRule::find($tcp)->upstream_port);
        // proxy-rule-*.conf and stream.conf are written only by the full rebuild.
        $this->assertSame(1, $this->fullRebuilds);
        $this->assertSame(1, $this->reloads);

        $switch->move([32771 => 3000], $moved);

        $this->assertSame(3000, ProxyRule::find($http)->upstream_port);
        $this->assertSame(3000, ProxyRule::find($tcp)->upstream_port);
        $this->assertSame(2, $this->fullRebuilds);
    }

    public function test_a_rule_switched_off_while_moved_still_goes_back(): void
    {
        $http = $this->operatorRule('acme', 'http', 18691, 3000);
        $switch = new RouteSwitch($this->system(), 'acme');
        $moved = $switch->move([3000 => 32771]);
        ProxyRule::query()->whereKey($http)->update(['enabled' => false]);

        $switch->move([32771 => 3000], $moved);

        $this->assertSame(3000, ProxyRule::find($http)->upstream_port);
        $this->assertFalse(ProxyRule::find($http)->enabled, 'its owner switched it off');
    }

    /** A new version on another port: the operator's rules to the old one follow, nothing else does. */
    public function test_an_operators_rules_follow_the_app_to_its_new_port(): void
    {
        $generated = $this->rule('acme', 'acme.example.test', 443, 3000);
        $http = $this->operatorRule('acme', 'http', 18691, 3000);
        $tcp = $this->operatorRule('acme', 'tcp', 25691, 3000);
        $off = $this->operatorRule('acme', 'http', 18693, 3000, enabled: false);
        $admin = $this->operatorRule('acme', 'http', 18692, 9000);
        $neighbour = $this->operatorRule('other', 'http', 18694, 3000);

        $followed = (new RouteSwitch($this->system(), 'acme'))->follow(3000, 8080);

        $this->assertEqualsCanonicalizing([$http, $tcp, $off], $followed);
        $this->assertSame(8080, ProxyRule::find($http)->upstream_port);
        $this->assertSame(8080, ProxyRule::find($tcp)->upstream_port);
        $this->assertSame(8080, ProxyRule::find($off)->upstream_port);
        $this->assertFalse(ProxyRule::find($off)->enabled, 'a rule its owner switched off stays off');
        $this->assertSame(9000, ProxyRule::find($admin)->upstream_port, 'another port of the app');
        $this->assertSame(3000, ProxyRule::find($neighbour)->upstream_port, 'another account');
        $this->assertSame(3000, ProxyRule::find($generated)->upstream_port, 'the engine routes its own');
        $this->assertSame(1, $this->fullRebuilds);
        $this->assertSame(1, $this->reloads);
    }

    public function test_following_only_switched_off_rules_reloads_nothing(): void
    {
        $off = $this->operatorRule('acme', 'tcp', 25691, 3000, enabled: false);

        $this->assertSame([$off], (new RouteSwitch($this->system(), 'acme'))->follow(3000, 8080));
        $this->assertSame(8080, ProxyRule::find($off)->upstream_port);
        $this->assertSame(0, $this->fullRebuilds);
        $this->assertSame(0, $this->reloads);
    }

    private function operatorRule(string $user, string $transport, int $listen, int $upstream, bool $enabled = true): int
    {
        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $user, 'enabled' => $enabled, 'transport' => $transport,
            'listen_port' => $listen, 'upstream_host' => $user, 'upstream_port' => $upstream, 'is_generated' => false,
        ])->id;
    }

    private function rule(string $user, string $server, int $listen, int $upstream): int
    {
        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $user, 'enabled' => true, 'transport' => 'http',
            'listen_port' => $listen, 'server_name' => $server, 'upstream_host' => $user,
            'upstream_port' => $upstream, 'is_generated' => true,
        ])->id;
    }

    private function domain(string $name): int
    {
        return (int) DB::table('domains')->insertGetId(['user_id' => 1, 'domain' => $name, 'type' => 'main']);
    }

    private function system(): System
    {
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('rebuildDomainConfig')->willReturnCallback(function (Domain $domain): void {
            $this->rebuilt[] = (string) $domain->domain;
        });
        $webserver->method('reload')->willReturnCallback(function (): void {
            $this->reloads++;
        });
        $webserver->method('rebuildConfig')->willReturnCallback(function (): void {
            $this->fullRebuilds++;
        });
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);

        return $system;
    }
}
