<?php

namespace Tests\Unit\Models;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A domain's generated rule on a port gives way to a hand-made one for that
 * domain and port: the project's own or the operator's. Another project's rule
 * named after the domain is never rendered, so it must not take the place.
 */
class ProxyRuleGeneratedPrecedenceTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
        $this->makeUser('bob');
    }

    public function test_the_projects_own_or_the_operators_rule_beats_the_default(): void
    {
        $this->handMade('user', 'alice', 80);
        $this->handMade('system', null, 443);

        $this->assertNull(ProxyRule::upsertGeneratedHttpRule('alice', 'alice.test', 80, 3000));
        $this->assertNull(ProxyRule::upsertGeneratedHttpRule('alice', 'alice.test', 443, 3000));
    }

    public function test_another_projects_rule_named_after_the_domain_does_not(): void
    {
        $this->handMade('user', 'bob', 80);

        $rule = ProxyRule::upsertGeneratedHttpRule('alice', 'alice.test', 80, 3000);

        $this->assertNotNull($rule);
        $this->assertSame(['alice', 'alice', 3000], [$rule->username, $rule->upstream_host, $rule->upstream_port]);
    }

    private function handMade(string $scope, ?string $username, int $port): void
    {
        ProxyRule::query()->create([
            'owner_scope' => $scope, 'username' => $username, 'enabled' => true, 'transport' => 'http',
            'listen_ip' => '*', 'listen_port' => $port, 'server_name' => 'alice.test',
            'upstream_host' => $username ?? '10.0.0.5', 'upstream_port' => 8080, 'is_generated' => false,
        ]);
    }
}
