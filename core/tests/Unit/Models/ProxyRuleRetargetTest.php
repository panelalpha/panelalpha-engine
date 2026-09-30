<?php

namespace Tests\Unit\Models;

use App\Models\ProxyRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Domain rename must move ProxyRule.server_name, not delete-and-forget.
 */
class ProxyRuleRetargetTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->timestamps();
        });

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
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_retarget_moves_generated_80_and_443_to_the_new_fqdn(): void
    {
        $this->seedGeneratedPair('nodedemo', 'old.example.test', 3000);

        $updated = ProxyRule::retargetServerName('nodedemo', 'old.example.test', 'new.example.test');

        $this->assertSame(2, $updated);
        $rules = ProxyRule::forUser('nodedemo')->orderBy('listen_port')->get();
        $this->assertCount(2, $rules);
        foreach ($rules as $rule) {
            $this->assertSame('new.example.test', $rule->server_name);
            $this->assertSame(3000, $rule->upstream_port);
            $this->assertTrue($rule->is_generated);
        }
        $this->assertSame([80, 443], $rules->pluck('listen_port')->all());
    }

    public function test_retarget_also_moves_manual_rules_on_the_old_fqdn(): void
    {
        ProxyRule::create([
            'owner_scope' => 'user',
            'username' => 'shop',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 8080,
            'server_name' => 'old.example.test',
            'upstream_host' => 'shop',
            'upstream_port' => 9000,
            'upstream_protocol' => 'http',
            'is_generated' => false,
        ]);

        $updated = ProxyRule::retargetServerName('shop', 'old.example.test', 'new.example.test');

        $this->assertSame(1, $updated);
        $rule = ProxyRule::forUser('shop')->first();
        $this->assertNotNull($rule);
        $this->assertSame('new.example.test', $rule->server_name);
        $this->assertSame(9000, $rule->upstream_port);
        $this->assertFalse($rule->is_generated);
    }

    public function test_retarget_moves_www_alias_rules(): void
    {
        ProxyRule::create([
            'owner_scope' => 'user',
            'username' => 'shop',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => 'www.old.example.test',
            'upstream_host' => 'shop',
            'upstream_port' => 3000,
            'upstream_protocol' => 'http',
            'is_generated' => false,
        ]);

        $updated = ProxyRule::retargetServerName('shop', 'old.example.test', 'new.example.test');

        $this->assertSame(1, $updated);
        $this->assertSame(
            'www.new.example.test',
            ProxyRule::forUser('shop')->value('server_name')
        );
    }

    public function test_ensure_generated_http_pair_creates_80_and_443(): void
    {
        ProxyRule::ensureGeneratedHttpPair('nodedemo', 'app.example.test', 3000);

        $rules = ProxyRule::forUser('nodedemo')->orderBy('listen_port')->get();
        $this->assertCount(2, $rules);
        $this->assertSame([80, 443], $rules->pluck('listen_port')->all());
        foreach ($rules as $rule) {
            $this->assertSame('app.example.test', $rule->server_name);
            $this->assertSame('nodedemo', $rule->upstream_host);
            $this->assertSame(3000, $rule->upstream_port);
            $this->assertTrue($rule->is_generated);
        }
    }

    public function test_retarget_does_not_touch_another_project(): void
    {
        $this->seedGeneratedPair('alice', 'old.example.test', 3000);
        $this->seedGeneratedPair('bob', 'old.example.test', 3000);

        ProxyRule::retargetServerName('alice', 'old.example.test', 'new.example.test');

        $this->assertSame(
            2,
            ProxyRule::forUser('alice')->where('server_name', 'new.example.test')->count()
        );
        $this->assertSame(
            2,
            ProxyRule::forUser('bob')->where('server_name', 'old.example.test')->count()
        );
    }

    public function test_retarget_is_a_no_op_when_from_equals_to(): void
    {
        $this->seedGeneratedPair('nodedemo', 'same.example.test', 3000);

        $updated = ProxyRule::retargetServerName('nodedemo', 'same.example.test', 'same.example.test');

        $this->assertSame(0, $updated);
        $this->assertSame(
            2,
            ProxyRule::forUser('nodedemo')->where('server_name', 'same.example.test')->count()
        );
    }

    public function test_project_update_retargets_proxy_rules_before_creating_the_new_vhost(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/UserController.php'));
        $this->assertIsString($controller);
        $updatePos = strpos($controller, 'function update($username, UserUpdateRequest $request)');
        $this->assertNotFalse($updatePos);
        $suspendPos = strpos($controller, 'function suspend(string $username)', $updatePos);
        $this->assertNotFalse($suspendPos);
        $this->assertStringContainsString(
            'MainDomainRename::apply(',
            substr($controller, $updatePos, $suspendPos - $updatePos),
            'project_update must rename the main domain through MainDomainRename'
        );

        // The rename itself lives in MainDomainRename::apply().
        $source = file_get_contents(app_path('Lib/Domains/MainDomainRename.php'));
        $this->assertIsString($source);
        $applyPos = strpos($source, 'public static function apply(User $user, string $newFqdn)');
        $this->assertNotFalse($applyPos);
        $nextPos = strpos($source, 'public static function replacement(', $applyPos);
        $this->assertNotFalse($nextPos);
        $updateBlock = substr($source, $applyPos, $nextPos - $applyPos);

        $retargetPos = strpos($updateBlock, 'ProxyRule::retargetServerName');
        $createPos = strpos($updateBlock, 'projectDomain()->create()');
        $this->assertNotFalse($retargetPos, 'project_update must retarget proxy rules on domain change');
        $this->assertNotFalse($createPos, 'project_update must still create the new domain vhost');
        $this->assertLessThan(
            $createPos,
            $retargetPos,
            'retargetServerName must run before projectDomain()->create() so the new vhost sees the rules'
        );
        $this->assertStringNotContainsString(
            'detectAndCreateProxyRules',
            $updateBlock,
            'domain rename must not re-run deploy-time port detection'
        );
        $this->assertStringContainsString('ensureGeneratedHttpPair', $updateBlock);
    }

    private function seedGeneratedPair(string $username, string $fqdn, int $upstreamPort): void
    {
        foreach ([80, 443] as $listenPort) {
            ProxyRule::create([
                'owner_scope' => 'user',
                'username' => $username,
                'enabled' => true,
                'transport' => 'http',
                'listen_ip' => '*',
                'listen_port' => $listenPort,
                'server_name' => $fqdn,
                'upstream_host' => $username,
                'upstream_port' => $upstreamPort,
                'upstream_protocol' => 'http',
                'is_generated' => true,
            ]);
        }
    }
}
