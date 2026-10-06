<?php

namespace Tests\Unit\System;

use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Services\Webserver\NginxProxy;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A domain's vhost is built from the proxy rules named after it. Another
 * project's rule stored with this domain's name took the vhost over, on 80,
 * on 443 or on a port of its own; it is left out of it.
 */
class DindVhostRuleServerNameTest extends TestCase
{
    use InMemoryDatabase;

    public function test_another_projects_rule_named_after_the_domain_is_left_out(): void
    {
        $this->bootInMemoryDatabase();
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'alice.test');
        $this->makeMainDomain($this->makeUser('bob'), 'bob.test');
        $this->rule('user', 'alice', 80, 'alice');
        $this->rule('user', 'bob', 80, 'bob', '10.0.0.7');
        $this->rule('user', 'bob', 443, 'bob');
        $this->rule('user', 'bob', 8089, 'bob');
        $this->rule('system', null, 9443, '10.0.0.5');

        $vars = $this->vars($alice);

        $this->assertSame('alice', $vars['proxy_http']['host'] ?? null);
        $this->assertNull($vars['proxy_https']);
        $this->assertSame([[9443, '10.0.0.5']], array_map(
            static fn (array $extra): array => [$extra['listen_port'], $extra['host']],
            $vars['proxy_extra']
        ));
    }

    private function rule(string $scope, ?string $username, int $port, string $upstream, string $listenIp = '*'): void
    {
        ProxyRule::query()->create([
            'owner_scope' => $scope, 'username' => $username, 'enabled' => true, 'transport' => 'http',
            'listen_ip' => $listenIp, 'listen_port' => $port, 'server_name' => 'alice.test',
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
