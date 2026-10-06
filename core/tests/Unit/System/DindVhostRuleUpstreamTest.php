<?php

namespace Tests\Unit\System;

use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Services\Webserver\NginxProxy;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A domain's vhost is built from the proxy rules named after it. A project's
 * rule stored before upstreams were checked, pointing at another project or
 * an engine service, is left out of it.
 */
class DindVhostRuleUpstreamTest extends TestCase
{
    use InMemoryDatabase;

    public function test_a_project_rule_to_anything_but_its_own_app_is_left_out(): void
    {
        $this->bootInMemoryDatabase();
        $alice = $this->makeUser('alice');
        $this->makeUser('bob');
        foreach ([80 => 'bob', 443 => 'alice', 8443 => '172.25.0.2', 8444 => 'core'] as $port => $upstream) {
            $this->rule('user', 'alice', $port, $upstream);
        }
        $this->rule('system', null, 9443, '10.0.0.5');

        $vars = $this->vars($alice);

        $this->assertNull($vars['proxy_http']);
        $this->assertSame('alice', $vars['proxy_https']['host'] ?? null);
        $this->assertSame([[9443, '10.0.0.5']], array_map(
            static fn (array $extra): array => [$extra['listen_port'], $extra['host']],
            $vars['proxy_extra']
        ));
    }

    private function rule(string $scope, ?string $username, int $port, string $upstream): void
    {
        ProxyRule::query()->create([
            'owner_scope' => $scope, 'username' => $username, 'enabled' => true, 'transport' => 'http',
            'listen_ip' => '*', 'listen_port' => $port, 'server_name' => 'alice.test',
            'upstream_host' => $upstream, 'upstream_port' => 8080, 'upstream_protocol' => 'http', 'is_generated' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function vars(User $user): array
    {
        $proxy = new NginxProxy(new System());

        return (fn (): array => $this->dindProxyTemplateVars($user, 'alice.test'))->call($proxy);
    }
}
