<?php

namespace Tests\Unit\System\Services;

use Tests\Support\TestNetwork;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\System;
use App\System\Services\Webserver\RoutingCompiler;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Pins every field of every compiled rule, in order, for a mix of domains and stored rules.
 */
class RoutingCompilerCharacterisationTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_whole_compiled_rule_set(): void
    {
        $alice = $this->domain('alice', 'alice.test', ['app_port' => 3000], ['aliases' => ['www.alice.test']]);
        $bob = $this->domain('bob', 'bob.test', [], ['ssl_disabled' => true]);
        $carol = $this->domain('carol', 'carol.test', ['app_port' => 4000], []);
        $this->rule(['listen_port' => 80, 'server_name' => 'www.alice.test', 'upstream_protocol' => 'https']);
        $this->rule(['listen_port' => 443, 'server_name' => 'carol.test']);
        $this->rule(['listen_ip' => TestNetwork::add(TestNetwork::PRIVATE_10, 2), 'listen_port' => 80, 'server_name' => null]);
        $this->rule(['transport' => 'tcp', 'listen_port' => 5432, 'upstream_port' => 5432]);
        $this->rule(['transport' => 'udp', 'listen_ip' => TestNetwork::add(TestNetwork::PRIVATE_10, 3), 'listen_port' => 53, 'upstream_port' => 53]);
        $this->rule(['owner_scope' => 'user', 'username' => 'carol', 'listen_port' => 8080, 'server_name' => 'carol.test', 'upstream_host' => 'carol']);

        $compiled = (new RoutingCompiler(new System))->compile([$alice, $bob, $carol]);

        $this->assertSnapshot('routing-compiler-mix', (string) json_encode($compiled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_a_port_wide_rule_takes_every_generated_vhost_on_its_port(): void
    {
        $alice = $this->domain('alice', 'alice.test', [], []);
        $bob = $this->domain('bob', 'bob.test', [], []);
        $this->rule(['listen_port' => 443, 'server_name' => null]);

        $compiled = (new RoutingCompiler(new System))->compile([$alice, $bob]);

        $this->assertSnapshot('routing-compiler-port-wide', (string) json_encode($compiled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @param array<string, mixed> $userDetails
     * @param array<string, mixed> $domainDetails
     */
    private function domain(string $username, string $name, array $userDetails, array $domainDetails): Domain
    {
        $domain = $this->makeMainDomain($this->makeUser($username, $userDetails, $name), $name);
        $domain->setDetails($domainDetails);
        $domain->save();

        return $domain;
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes): void
    {
        ProxyRule::make(array_merge([
            'owner_scope' => 'system',
            'enabled' => true,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'upstream_host' => TestNetwork::add(TestNetwork::PRIVATE_10, 8),
            'upstream_port' => 8080,
            'is_generated' => false,
        ], $attributes))->save();
    }

    private function assertSnapshot(string $name, string $actual): void
    {
        $file = dirname(__DIR__, 3).'/fixtures/webserver-snapshots/'.$name.'.txt';
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
        }
        $this->assertFileExists($file);
        $this->assertSame(file_get_contents($file), $actual);
    }
}
