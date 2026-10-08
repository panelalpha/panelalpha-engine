<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * A project's rule answers for no name or for its own domains and aliases. A
 * rule named after another project's domain was rendered into that domain's
 * vhost, on a port the engine opened. MCP goes through the same calls.
 */
class ProxyRuleServerNameHttpTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private const REFUSED = "The server name must be empty or one of the project's own domains or aliases: "
        . "a project's rule cannot answer for another project's site.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->withoutMiddleware(Authenticate::class);
        $alice = $this->makeMainDomain($this->makeUser('alice'), 'alice.test');
        $alice->addAlias('www.alice.test');
        $alice->save();
        $this->makeMainDomain($this->makeUser('bob'), 'bob.test');
    }

    /** @return array<string, mixed> */
    private function rule(string $username, ?string $serverName, array $extra = []): array
    {
        return $extra + ['owner_scope' => 'user', 'username' => $username, 'transport' => 'http', 'listen_port' => 8089,
            'server_name' => $serverName, 'upstream_host' => $username, 'upstream_port' => 80];
    }

    public function test_a_project_rule_named_after_another_projects_site_is_refused(): void
    {
        foreach (['alice.test', 'www.alice.test', 'ALICE.test', 'nobody.test', '*.alice.test'] as $name) {
            $this->postJson('/api/proxy-rules', $this->rule('bob', $name))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_name.0', self::REFUSED);
        }
        // On 80 too, on an address the generated rule does not hold.
        $this->postJson('/api/proxy-rules', $this->rule('bob', 'alice.test', ['listen_port' => 80, 'listen_ip' => '10.0.0.7']))
            ->assertStatus(422);

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }

    public function test_a_project_rule_on_its_own_domain_or_alias_or_no_name_is_stored(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice', 'alice.test'))->assertCreated();
        $this->postJson('/api/proxy-rules', $this->rule('alice', 'WWW.alice.test', ['listen_port' => 8090]))->assertCreated();
        $this->postJson('/api/proxy-rules', $this->rule('alice', null, ['listen_port' => 8091]))->assertCreated();
        // A stream rule has no server name at all.
        $this->postJson('/api/proxy-rules', $this->rule('bob', 'alice.test', ['transport' => 'tcp', 'listen_port' => 17002]))
            ->assertCreated();

        $this->assertSame(4, ProxyRule::query()->count());
        $this->assertNull(ProxyRule::query()->where('username', 'bob')->sole()->server_name);
    }

    /** The operator's own rules may name any site. */
    public function test_a_system_rule_may_name_any_site(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice', 'alice.test', ['owner_scope' => 'system', 'upstream_host' => '10.0.0.5']))
            ->assertCreated();

        $this->assertSame('system', ProxyRule::query()->sole()->owner_scope);
    }

    /** One stored before the check cannot be renamed through an update, so it can only be switched off. */
    public function test_a_rule_stored_before_the_check_can_only_be_switched_off(): void
    {
        $rule = ProxyRule::query()->create($this->rule('bob', 'alice.test', ['listen_ip' => '*', 'enabled' => true]));

        $this->putJson("/api/proxy-rules/{$rule->id}", ['upstream_port' => 81])
            ->assertStatus(422)
            ->assertJsonPath('errors.server_name.0', self::REFUSED);
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => false])->assertOk();
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => true])->assertStatus(422);

        $this->assertSame([80, false], [$rule->fresh()->upstream_port, $rule->fresh()->enabled]);
    }
}
