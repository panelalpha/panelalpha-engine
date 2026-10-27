<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * A project's rule answers for no name or for its own domains. A rule named
 * after another project's domain was rendered into that domain's vhost, on a
 * port the engine opened; one named after an alias or a traditional PHP
 * project's domain was rendered nowhere. MCP goes through the same calls.
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
        $alice = $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'alice.test');
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

    /** REST and the CLI share one grammar: `_` is a name, a bare `*` is not. */
    public function test_underscore_is_accepted_and_a_bare_star_is_refused(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice', '_', ['owner_scope' => 'system', 'upstream_host' => '10.0.0.5']))
            ->assertCreated();

        $this->postJson('/api/proxy-rules', $this->rule('alice', '*'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_name.0', 'The server name must be "_" or a hostname.');

        $this->assertSame('_', ProxyRule::query()->sole()->server_name);
    }

    public function test_a_project_rule_on_its_own_domain_or_no_name_is_stored(): void
    {
        $this->postJson('/api/proxy-rules', $this->rule('alice', 'alice.test'))->assertCreated();
        $this->postJson('/api/proxy-rules', $this->rule('alice', 'ALICE.test', ['listen_port' => 8090]))->assertCreated();
        $this->postJson('/api/proxy-rules', $this->rule('alice', null, ['listen_port' => 8091]))->assertCreated();
        // A stream rule has no server name at all.
        $this->postJson('/api/proxy-rules', $this->rule('bob', 'alice.test', ['transport' => 'tcp', 'listen_port' => 17002]))
            ->assertCreated();

        $this->assertSame(4, ProxyRule::query()->count());
        $this->assertNull(ProxyRule::query()->where('username', 'bob')->sole()->server_name);
    }

    /** The domain's own vhost answers for an alias and renders only rules named after the domain. */
    public function test_a_rule_named_after_an_alias_is_refused_on_every_port(): void
    {
        $refused = 'www.alice.test is an alias of alice.test, whose own site answers for it, so a rule named after it '
            . 'would never be served. Name the rule alice.test: a rule for the domain serves its aliases too.';
        foreach ([[80, 'user'], [443, 'system'], [8089, 'user'], [8089, 'system']] as [$port, $scope]) {
            $this->postJson('/api/proxy-rules', $this->rule('alice', 'www.alice.test', ['listen_port' => $port, 'owner_scope' => $scope]))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_name.0', $refused);
        }

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }

    /** A traditional PHP project's vhost renders no proxy rule at all. */
    public function test_a_rule_named_after_a_traditional_php_projects_domain_or_alias_is_refused(): void
    {
        $carol = $this->makeMainDomain($this->makeUser('carol'), 'carol.test');
        $carol->addAlias('www.carol.test');
        $carol->save();

        foreach (['carol.test', 'WWW.carol.test'] as $name) {
            $this->postJson('/api/proxy-rules', $this->rule('carol', $name, ['owner_scope' => 'system', 'upstream_host' => '10.0.0.5']))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_name.0', "{$name} belongs to the traditional PHP project carol, whose own site "
                    . 'answers for it, so a rule named after it would never be served.');
        }
        $this->postJson('/api/proxy-rules', $this->rule('carol', 'carol.test', ['enabled' => false]))->assertStatus(422);

        $this->assertSame(0, ProxyRule::query()->count());
    }

    /** One stored before the check cannot be renamed through an update, so it can only be switched off. */
    public function test_a_stored_alias_rule_can_only_be_switched_off(): void
    {
        $rule = ProxyRule::query()->create($this->rule('alice', 'www.alice.test', ['listen_ip' => '*', 'enabled' => true]));

        $this->putJson("/api/proxy-rules/{$rule->id}", ['upstream_port' => 81])
            ->assertStatus(422)
            ->assertJsonPath('errors.server_name.0', fn (string $message): bool => str_starts_with($message, 'www.alice.test is an alias of alice.test'));
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => false])->assertOk();
        $this->putJson("/api/proxy-rules/{$rule->id}", ['enabled' => true])->assertStatus(422);

        $this->assertSame([80, false], [$rule->fresh()->upstream_port, $rule->fresh()->enabled]);
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
