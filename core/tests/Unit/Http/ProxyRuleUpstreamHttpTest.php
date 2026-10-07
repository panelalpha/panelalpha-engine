<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * A project's rule reaches that project's own app and nothing else. The proxy
 * runs on the host's network and its port is opened to the internet, so a rule
 * to another project, sites-db, core or a registry published it. MCP goes
 * through the same calls.
 */
class ProxyRuleUpstreamHttpTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private const REFUSED = "The upstream must be the project's own app, 'alice': "
        . "a project's rule cannot reach another project, the engine's services or the host.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('alice');
        $this->makeUser('bob');
    }

    /** @return array<string, mixed> */
    private function rule(string $upstream, array $extra = []): array
    {
        return $extra + ['owner_scope' => 'user', 'username' => 'alice', 'transport' => 'tcp', 'listen_port' => 17002,
            'upstream_host' => $upstream, 'upstream_port' => 3306];
    }

    public function test_a_project_rule_to_anything_but_its_own_app_is_refused(): void
    {
        $foreign = [
            'bob',                          // another project
            '172.25.0.2',                   // sites-db on pash-default-network
            '10.200.0.2',                   // sites-db on pash-tenants
            'sites-db',
            'core',
            'panelalpha-cache-registry',
            'panelalpha-registry-proxy',
            '127.0.0.1',                    // the host itself
            '10.10.0.44',
            '::1',
            'db.example.com',               // a name that may resolve to any of those
            'alice.example.com',
            'dind',                         // every account's own service name
        ];
        foreach ($foreign as $upstream) {
            $this->postJson('/api/proxy-rules', $this->rule($upstream))
                ->assertStatus(422)
                ->assertJsonPath('errors.upstream_host.0', self::REFUSED);
        }
        // owner_scope defaults to user.
        $this->postJson('/api/proxy-rules', ['username' => 'alice', 'transport' => 'http', 'listen_port' => 17003,
            'upstream_host' => 'bob', 'upstream_port' => 80])->assertStatus(422);

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }

    public function test_a_project_rule_to_its_own_app_is_stored(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice', ['upstream_port' => 8080]))->assertCreated();

        $rule = ProxyRule::query()->sole();
        $this->assertSame(['alice', 'alice', 8080], [$rule->username, $rule->upstream_host, $rule->upstream_port]);
        $this->assertWebserverApplied(1);
    }

    /** The operator's own rules may point anywhere the host reaches. */
    public function test_a_system_rule_may_name_any_upstream(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('172.25.0.2', ['owner_scope' => 'system', 'username' => null]))
            ->assertCreated();

        $this->assertSame('system', ProxyRule::query()->sole()->owner_scope);
    }

    public function test_an_update_cannot_point_a_project_rule_elsewhere(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice'))->assertCreated();
        $id = ProxyRule::query()->sole()->id;

        foreach (['bob', 'sites-db', '172.25.0.2', '127.0.0.1'] as $upstream) {
            $this->putJson("/api/proxy-rules/{$id}", ['upstream_host' => $upstream])
                ->assertStatus(422)
                ->assertJsonPath('errors.upstream_host.0', self::REFUSED);
        }
        $this->assertSame('alice', ProxyRule::query()->sole()->upstream_host);

        $this->putJson("/api/proxy-rules/{$id}", ['upstream_host' => 'alice', 'upstream_port' => 8081])->assertOk();
        $this->assertSame(8081, ProxyRule::query()->sole()->upstream_port);
    }

    /** One stored before the check can be switched off or fixed, and nothing else. */
    public function test_a_rule_stored_before_the_check_is_fixed_or_switched_off(): void
    {
        $rule = ProxyRule::query()->create($this->rule('172.25.0.2', ['listen_ip' => '*', 'enabled' => true]));

        $this->putJson("/api/proxy-rules/{$rule->id}", ['upstream_port' => 3307])->assertStatus(422);
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => false])->assertOk();
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => true])->assertStatus(422);
        $this->putJson("/api/proxy-rules/{$rule->id}", ['upstream_host' => 'alice', 'enabled' => true])->assertOk();

        $this->assertSame(['alice', true], [$rule->fresh()->upstream_host, $rule->fresh()->enabled]);
    }
}
