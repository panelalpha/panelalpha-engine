<?php

namespace Tests\Unit\Models;

use App\Models\ProxyRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The generated :80/:443 rules proxy with the protocol the recipe declares. */
class ProxyRuleUpstreamProtocolTest extends TestCase
{
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
            $table->string('owner_scope')->default('system');
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    public function test_a_declared_https_port_gets_https_rules_on_80_and_443(): void
    {
        ProxyRule::ensureGeneratedHttpPair('unifi', 'unifi.example.test', 8443, 'https');

        $rules = ProxyRule::forUser('unifi')->orderBy('listen_port')->get();
        $this->assertSame([80, 443], $rules->pluck('listen_port')->all());
        $this->assertSame(['https', 'https'], $rules->pluck('upstream_protocol')->all());
        $this->assertSame([8443, 8443], $rules->pluck('upstream_port')->all());
    }

    public function test_no_declared_scheme_stays_http(): void
    {
        ProxyRule::upsertGeneratedHttpRule('shop', 'shop.example.test', 443, 8000, true);

        $this->assertSame('http', ProxyRule::forUser('shop')->value('upstream_protocol'));
    }

    public function test_the_sync_a_deploy_runs_carries_the_scheme(): void
    {
        ProxyRule::syncGeneratedHttpPair('unifi', 'unifi.example.test', 8443, 'https');

        $this->assertSame(['https', 'https'], ProxyRule::forUser('unifi')->orderBy('listen_port')->pluck('upstream_protocol')->all());
    }

    public function test_an_operators_https_stays_but_a_recipes_is_dropped_with_the_declaration(): void
    {
        ProxyRule::syncGeneratedHttpPair('ops', 'ops.example.test', 8000);
        ProxyRule::forUser('ops')->update(['upstream_protocol' => 'https']);
        ProxyRule::syncGeneratedHttpPair('ops', 'ops.example.test', 8000);
        $this->assertSame(['https', 'https'], ProxyRule::forUser('ops')->pluck('upstream_protocol')->all());

        ProxyRule::syncGeneratedHttpPair('rcp', 'rcp.example.test', 8443, 'https');
        ProxyRule::syncGeneratedHttpPair('rcp', 'rcp.example.test', 8443);
        $this->assertSame(['http', 'http'], ProxyRule::forUser('rcp')->pluck('upstream_protocol')->all());
    }
}
