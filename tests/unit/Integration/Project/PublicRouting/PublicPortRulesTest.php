<?php

namespace Tests\Integration\Project\PublicRouting;

use App\Lib\Project\PublicRouting\PublicPort;
use App\Lib\Project\PublicRouting\PublicPortRules;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\System;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The tcp/udp rules `public_ports:` makes follow the file and the operator's permission, and nothing else.
 */
class PublicPortRulesTest extends TestCase
{
    private int $rebuilds = 0;

    private PublicRoutingGrants $grants;

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
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null]);
        $this->grants = new PublicRoutingGrants;
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Schema::dropIfExists('proxy_rules');
        Schema::dropIfExists('domains');
        parent::tearDown();
    }

    public function test_nothing_opens_until_the_operator_allows_it(): void
    {
        $states = $this->rules()->apply('game', $this->routing(new PublicPort(27015, 27015, PublicPort::UDP)));

        $this->assertSame(PublicPortRules::NOT_ALLOWED, $states[0]['state']);
        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertSame(0, $this->rebuilds);
    }

    public function test_an_allowed_port_becomes_a_rule_that_opens_the_firewall(): void
    {
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);

        $states = $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000)));

        $rule = ProxyRule::query()->sole();
        $this->assertSame(['tcp', 8081, 'game', 3000, false], [$rule->transport, $rule->listen_port, $rule->upstream_host, $rule->upstream_port, $rule->is_generated]);
        $this->assertTrue(PublicPortRules::isDeclared($rule));
        $this->assertSame(['listen' => 8081, 'to' => 3000, 'transport' => 'tcp', 'state' => 'open', 'rule_id' => $rule->id], $states[0]);
        $this->assertSame(1, $this->rebuilds);
    }

    public function test_a_redeploy_with_the_same_file_changes_nothing(): void
    {
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);
        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000)));
        ProxyRule::query()->update(['upstream_port' => 41234]);

        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000)));

        $this->assertSame(41234, ProxyRule::query()->sole()->upstream_port, 'a redeploy beside the running version moved it');
        $this->assertSame(1, $this->rebuilds);
    }

    public function test_a_new_to_moves_the_rule(): void
    {
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);
        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000)));

        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 4000)));

        $this->assertSame(4000, ProxyRule::query()->sole()->upstream_port);
    }

    public function test_a_line_taken_out_closes_its_port_and_leaves_hand_made_rules(): void
    {
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);
        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000), new PublicPort(8082, 3000)));
        $hand = ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => 'game', 'transport' => 'tcp', 'listen_port' => 9090,
            'upstream_host' => 'game', 'upstream_port' => 3000, 'is_generated' => false,
        ]);

        $this->rules()->apply('game', $this->routing(new PublicPort(8082, 3000)));

        $this->assertEqualsCanonicalizing([8082, 9090], ProxyRule::query()->pluck('listen_port')->all());
        $this->assertNotNull(ProxyRule::find($hand->id));
    }

    public function test_a_port_another_project_holds_is_refused_and_theirs_stays(): void
    {
        $theirs = ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => 'other', 'transport' => 'tcp', 'listen_port' => 8081,
            'upstream_host' => 'other', 'upstream_port' => 80, 'is_generated' => false,
        ]);
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);

        $states = $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000)));

        $this->assertSame(PublicPortRules::REFUSED, $states[0]['state']);
        $this->assertNotEmpty($states[0]['reason']);
        $this->assertSame([$theirs->id], ProxyRule::query()->pluck('id')->all());
    }

    public function test_taking_the_permission_away_removes_every_declared_rule(): void
    {
        $this->grants->allow('game', [PublicRoutingGrants::PORTS]);
        $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000), new PublicPort(8082, 3000)));
        $this->grants->deny('game', [PublicRoutingGrants::PORTS]);

        $states = $this->rules()->apply('game', $this->routing(new PublicPort(8081, 3000), new PublicPort(8082, 3000)));

        $this->assertSame([PublicPortRules::NOT_ALLOWED, PublicPortRules::NOT_ALLOWED], array_column($states, 'state'));
        $this->assertSame(0, ProxyRule::query()->count());
    }

    public function test_grants_are_per_project_and_kind(): void
    {
        $this->assertSame([PublicRoutingGrants::PORTS, PublicRoutingGrants::HOST_DOMAIN], $this->grants->allow('panel', [PublicRoutingGrants::HOST_DOMAIN, PublicRoutingGrants::PORTS, 'bogus']));
        $this->assertSame([PublicRoutingGrants::PORTS], $this->grants->deny('panel', [PublicRoutingGrants::HOST_DOMAIN]));
        $this->assertFalse($this->grants->allows('other', PublicRoutingGrants::PORTS));

        $this->grants->forget('panel');

        $this->assertSame([], $this->grants->all());
        $this->assertNull(Setting::get(PublicRoutingGrants::SETTING));
    }

    private function routing(PublicPort ...$ports): PublicRouting
    {
        return new PublicRouting(PublicRouting::DOMAIN_GENERATED, array_values($ports));
    }

    private function rules(): PublicPortRules
    {
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('rebuildConfig')->willReturnCallback(function (): void {
            $this->rebuilds++;
        });
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);
        $system->method('execOnHost')->willReturn('');

        return new PublicPortRules($system, $this->grants);
    }
}
