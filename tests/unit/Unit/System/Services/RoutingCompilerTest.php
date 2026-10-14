<?php

namespace Tests\Unit\System\Services;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Services\Webserver\ProxyRulePorts;
use App\System\Services\Webserver\RoutingCompiler;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What nginx-proxy is told to serve: a default vhost per domain, minus anything
 * an operator's own rule has claimed, plus those rules.
 *
 * The file had no tests at all, which is how a dead suppression branch survived
 * in it.
 */
class RoutingCompilerTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    private function compile(array $domains = []): array
    {
        return (new RoutingCompiler(new System()))->compile($domains);
    }

    /** SSL is on unless a domain says otherwise, so this yields :80 and :443. */
    private function domainFor(string $username, string $domain, ?int $appPort = null): Domain
    {
        $user = $this->makeUser($username, $appPort === null ? [] : ['app_port' => $appPort], $domain);

        return $this->makeMainDomain($user, $domain);
    }

    private function plainDomainFor(string $username, string $domain): Domain
    {
        $user = $this->makeUser($username, [], $domain);
        $model = $this->makeMainDomain($user, $domain);
        $model->setDetails(['ssl_disabled' => true]);
        $model->save();

        return $model;
    }

    /** @return list<array<string, mixed>> */
    private function generated(array $http): array
    {
        return array_values(array_filter($http, fn (array $r): bool => $r['is_generated']));
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes): ProxyRule
    {
        /** @var ProxyRule $rule */
        $rule = ProxyRule::make(array_merge([
            'owner_scope' => 'system',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'upstream_host' => '10.0.0.5',
            'upstream_port' => 8080,
            'is_generated' => false,
        ], $attributes));
        $rule->save();

        return $rule;
    }

    // --- generated defaults ---

    public function test_no_domains_compiles_to_nothing(): void
    {
        $this->assertSame(['http' => [], 'stream' => []], $this->compile());
    }

    public function test_a_domain_gets_a_port_80_vhost_pointing_at_its_app(): void
    {
        $domain = $this->domainFor('alice', 'alice.test', 3000);

        $http = $this->compile([$domain])['http'];

        $this->assertSame([80, 443], array_column($http, 'listen_port'));
        $this->assertSame(80, $http[0]['listen_port']);
        $this->assertSame('alice.test', $http[0]['server_name']);
        $this->assertSame('localhost', $http[0]['upstream_host']);
        $this->assertSame(3000, $http[0]['upstream_port']);
        $this->assertTrue($http[0]['is_generated']);
        $this->assertFalse($http[0]['ssl_enabled']);
    }

    public function test_a_project_with_no_app_port_falls_back_to_80(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');

        $this->assertSame(80, $this->compile([$domain])['http'][0]['upstream_port']);
    }

    public function test_ssl_adds_a_443_vhost_with_the_project_certificate(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');

        $https = $this->compile([$domain])['http'][1];

        $this->assertSame(443, $https['listen_port']);
        $this->assertTrue($https['ssl_enabled']);
        $this->assertStringEndsWith('/ssl-certs/alice.test.pem', (string) $https['ssl_cert_pem_file']);
        $this->assertStringEndsWith('/ssl-certs/alice.test.key', (string) $https['ssl_cert_key_file']);
    }

    public function test_a_domain_with_ssl_disabled_gets_no_443_vhost(): void
    {
        $domain = $this->plainDomainFor('alice', 'alice.test');

        $this->assertSame([80], array_column($this->compile([$domain])['http'], 'listen_port'));
    }

    public function test_each_domain_gets_its_own_vhost(): void
    {
        $a = $this->domainFor('alice', 'alice.test');
        $b = $this->domainFor('bob', 'bob.test');

        $http = $this->compile([$a, $b])['http'];

        $this->assertSame(
            ['alice.test', 'bob.test'],
            array_values(array_unique(array_column($http, 'server_name')))
        );
    }

    // --- suppression ---

    /** An operator rule on :80 with no server_name claims the whole port. */
    public function test_a_port_wide_rule_suppresses_every_generated_vhost_on_that_port(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');
        $this->rule(['listen_port' => 80, 'server_name' => null]);

        $http = $this->compile([$domain])['http'];

        $this->assertSame(
            [443],
            array_column($this->generated($http), 'listen_port'),
            'the generated :80 default is gone, :443 is untouched'
        );
        $this->assertContains(false, array_column($http, 'is_generated'), 'the operator rule is there');
    }

    public function test_a_rule_on_another_port_leaves_the_default_alone(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');
        $this->rule(['listen_port' => 8080, 'server_name' => null]);

        $http = $this->compile([$domain])['http'];

        $this->assertSame(
            [80, 443],
            array_column($this->generated($http), 'listen_port'),
            'both defaults survive a :8080 rule'
        );
    }

    /** Named rules claim only the name, so other domains on the port keep theirs. */
    public function test_a_named_rule_suppresses_only_the_matching_domain(): void
    {
        $alice = $this->domainFor('alice', 'alice.test');
        $bob = $this->domainFor('bob', 'bob.test');
        $this->rule(['listen_port' => 80, 'server_name' => 'alice.test']);

        $http = $this->compile([$alice, $bob])['http'];

        $onPort80 = array_filter(
            $this->generated($http),
            fn (array $r): bool => $r['listen_port'] === 80
        );

        $this->assertSame(
            ['bob.test'],
            array_values(array_column($onPort80, 'server_name')),
            "alice's :80 default is claimed; bob's is not"
        );
    }

    public function test_a_rule_on_another_listen_ip_leaves_the_default_alone(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');
        $this->rule(['listen_ip' => '10.1.2.3', 'listen_port' => 80, 'server_name' => null]);

        $generated = $this->generated($this->compile([$domain])['http']);

        $this->assertSame([80, 443], array_column($generated, 'listen_port'));
    }

    public function test_a_disabled_rule_suppresses_nothing(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');
        $this->rule(['listen_port' => 80, 'server_name' => null, 'enabled' => false]);

        $http = $this->compile([$domain])['http'];

        $this->assertSame([80, 443], array_column($this->generated($http), 'listen_port'));
        $this->assertNotContains(false, array_column($http, 'is_generated'));
    }

    // --- persisted rules reaching the output ---

    public function test_an_http_rule_is_carried_through_with_its_upstream(): void
    {
        $this->rule(['listen_port' => 8080, 'upstream_host' => '10.0.0.9', 'upstream_port' => 9000]);

        $http = $this->compile()['http'];

        $this->assertCount(1, $http);
        $this->assertSame('10.0.0.9', $http[0]['upstream_host']);
        $this->assertSame(9000, $http[0]['upstream_port']);
        $this->assertFalse($http[0]['is_generated']);
    }

    public function test_a_tcp_rule_lands_in_the_stream_set_not_the_http_one(): void
    {
        $this->rule(['transport' => 'tcp', 'listen_port' => 5432]);

        $compiled = $this->compile();

        $this->assertSame([], $compiled['http']);
        $this->assertCount(1, $compiled['stream']);
        $this->assertSame('tcp', $compiled['stream'][0]['transport']);
        $this->assertSame(5432, $compiled['stream'][0]['listen_port']);
    }

    public function test_a_udp_rule_lands_in_the_stream_set(): void
    {
        $this->rule(['transport' => 'udp', 'listen_port' => 53]);

        $this->assertSame('udp', $this->compile()['stream'][0]['transport']);
    }

    // --- what a project's rule may reach ---

    /**
     * A project's rule stored before upstreams were checked, pointing at
     * another project or sites-db, is not served and gets no firewall port.
     */
    public function test_a_project_rule_to_anything_but_its_own_app_is_not_compiled(): void
    {
        $domain = $this->domainFor('alice', 'alice.test');
        $this->makeUser('bob');
        $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'transport' => 'tcp', 'listen_port' => 17002, 'upstream_host' => 'bob']);
        $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'transport' => 'tcp', 'listen_port' => 17003, 'upstream_host' => '172.25.0.2']);
        $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'listen_port' => 80, 'server_name' => null, 'upstream_host' => 'core']);
        $own = $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'transport' => 'tcp', 'listen_port' => 17004, 'upstream_host' => 'alice']);

        $compiled = $this->compile([$domain]);

        $this->assertSame([$own->id], array_column($compiled['stream'], 'id'));
        $this->assertSame([80, 443], array_column($this->generated($compiled['http']), 'listen_port'), 'the refused :80 rule claims nothing');
        $this->assertNotContains(false, array_column($compiled['http'], 'is_generated'));
        $this->assertSame(['17004'], array_values(array_map(
            static fn ($allow): string => (string) $allow->port,
            ProxyRulePorts::allows([...$compiled['http'], ...$compiled['stream']])
        )));
    }

    /**
     * A project's rule stored before server names were checked, named after
     * another project's domain, is not served and gets no firewall port.
     */
    public function test_a_project_rule_named_after_another_projects_domain_is_not_compiled(): void
    {
        $alice = $this->domainFor('alice', 'alice.test');
        $alice->setDetails(['aliases' => ['www.alice.test']]);
        $alice->save();
        $bob = $this->domainFor('bob', 'bob.test');
        $this->rule(['owner_scope' => 'user', 'username' => 'bob', 'listen_port' => 8089, 'server_name' => 'alice.test', 'upstream_host' => 'bob']);
        $this->rule(['owner_scope' => 'user', 'username' => 'bob', 'listen_port' => 8090, 'server_name' => 'www.alice.test', 'upstream_host' => 'bob']);
        $this->rule(['owner_scope' => 'user', 'username' => 'bob', 'listen_ip' => '10.1.2.3', 'listen_port' => 80, 'server_name' => 'alice.test', 'upstream_host' => 'bob']);
        $own = $this->rule(['owner_scope' => 'user', 'username' => 'bob', 'listen_port' => 8091, 'server_name' => 'BOB.test', 'upstream_host' => 'bob']);
        $alias = $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'listen_port' => 8092, 'server_name' => 'www.alice.test', 'upstream_host' => 'alice']);
        $system = $this->rule(['listen_port' => 8093, 'server_name' => 'alice.test']);

        $http = $this->compile([$alice, $bob])['http'];

        $ids = array_values(array_filter(array_column($http, 'id')));
        sort($ids);
        $this->assertSame([$own->id, $alias->id, $system->id], $ids);
        $ports = array_values(array_map(static fn ($allow): string => (string) $allow->port, ProxyRulePorts::allows($http)));
        sort($ports);
        $this->assertSame(['8091', '8092', '8093'], $ports);
    }

    /** The operator's own rules are not limited. */
    public function test_a_system_rule_to_any_upstream_is_compiled(): void
    {
        $this->rule(['transport' => 'tcp', 'listen_port' => 17003, 'upstream_host' => '172.25.0.2']);

        $this->assertSame('172.25.0.2', $this->compile()['stream'][0]['upstream_host']);
    }

    /**
     * The invariant the removed suppression branch depended on.
     *
     * generateDomainRules() only ever produces http defaults, so a tcp/udp rule
     * has nothing generated to suppress. If that ever changes, this fails --
     * and the suppression the branch was reaching for has to be written, and
     * written correctly: the old one filtered into a local and dropped it.
     */
    public function test_no_generated_stream_rules_exist_to_suppress(): void
    {
        $alice = $this->domainFor('alice', 'alice.test');
        $bob = $this->domainFor('bob', 'bob.test');
        $this->rule(['transport' => 'tcp', 'listen_port' => 5432]);

        $stream = $this->compile([$alice, $bob])['stream'];

        foreach ($stream as $rule) {
            $this->assertFalse(
                $rule['is_generated'],
                'a generated stream rule now exists; suppressConflictingDefaults() must handle it'
            );
        }
    }
}
