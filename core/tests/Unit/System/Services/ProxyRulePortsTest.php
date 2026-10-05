<?php

namespace Tests\Unit\System\Services;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\Ufw\UfwRules;
use App\System\Services\Webserver\ProxyRulePorts;
use Tests\TestCase;

/**
 * sites-http listens for a proxy rule on the host's own network, where the
 * default incoming policy drops everything not allowed.
 */
class ProxyRulePortsTest extends TestCase
{
    public function test_a_tcp_rule_gets_a_host_allow_for_its_port(): void
    {
        $firewall = $this->firewall();
        (new ProxyRulePorts($firewall))->sync([$this->rule(7, 'tcp', '*', 25212)]);

        $this->assertCount(1, $firewall->rules);
        $allow = array_values($firewall->rules)[0];
        $this->assertSame(['allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '25212', 'comment', 'panelalpha: proxy rule 7'], UfwRules::spec($allow));
        $this->assertTrue($allow->managed());
    }

    public function test_a_udp_rule_on_one_address_allows_udp_to_that_address(): void
    {
        $firewall = $this->firewall();
        (new ProxyRulePorts($firewall))->sync([$this->rule(8, 'udp', '10.0.0.5', 5353)]);

        $allow = array_values($firewall->rules)[0];
        $this->assertSame('udp', $allow->protocol);
        $this->assertSame('10.0.0.5', $allow->destination);
        $this->assertSame('5353', $allow->port);
    }

    public function test_the_allow_goes_once_the_rule_is_gone(): void
    {
        $firewall = $this->firewall();
        $ports = new ProxyRulePorts($firewall);
        $ports->sync([$this->rule(7, 'tcp', '*', 25212)]);
        $ports->sync([]);

        $this->assertSame([], $firewall->rules);
    }

    public function test_an_allow_already_there_is_not_added_again(): void
    {
        $firewall = $this->firewall();
        $ports = new ProxyRulePorts($firewall);
        $ports->sync([$this->rule(7, 'tcp', '*', 25212)]);
        $ports->sync([$this->rule(7, 'tcp', '*', 25212)]);

        $this->assertSame(1, $firewall->adds);
        $this->assertCount(1, $firewall->rules);
    }

    public function test_an_operators_own_rule_is_never_removed(): void
    {
        $own = new FirewallRule(FirewallRule::ALLOW, protocol: 'tcp', port: '25212', comment: 'office game server');
        $ssh = new FirewallRule(FirewallRule::ALLOW, protocol: 'tcp', port: '22', comment: 'panelalpha: ssh');
        $firewall = $this->firewall([$own, $ssh]);
        $ports = new ProxyRulePorts($firewall);

        // Same match as the operator's rule: nothing to add, and nothing to take back later.
        $ports->sync([$this->rule(7, 'tcp', '*', 25212)]);
        $ports->sync([]);

        $this->assertSame(0, $firewall->adds);
        $this->assertSame([$own->id(), $ssh->id()], array_keys($firewall->rules));
    }

    public function test_generated_rules_open_nothing(): void
    {
        $rule = $this->rule(7, 'tcp', '*', 25212);
        $rule['is_generated'] = true;

        $this->assertSame([], ProxyRulePorts::allows([$rule]));
    }

    public function test_an_http_rule_off_the_webservers_ports_gets_a_tcp_allow(): void
    {
        $firewall = $this->firewall();
        (new ProxyRulePorts($firewall))->sync([$this->rule(9, 'http', '2a01:4f8::1', 8081)]);

        $allow = array_values($firewall->rules)[0];
        $this->assertSame(['tcp', '8081', '2a01:4f8::1', 'panelalpha: proxy rule 9'], [$allow->protocol, $allow->port, $allow->destination, $allow->comment]);
    }

    public function test_http_rules_on_80_and_443_open_nothing(): void
    {
        $this->assertSame([], ProxyRulePorts::allows([$this->rule(9, 'http', '*', 80), $this->rule(10, 'http', '10.0.0.5', 443)]));
    }

    public function test_http_rules_sharing_a_port_share_one_allow(): void
    {
        $allows = ProxyRulePorts::allows([$this->rule(9, 'http', '*', 8081), $this->rule(10, 'http', '*', 8081)]);

        $this->assertCount(1, $allows);
        $this->assertSame('panelalpha: proxy rule 9', array_values($allows)[0]->comment);
    }

    public function test_a_firewall_that_fails_does_not_fail_the_caller(): void
    {
        $firewall = $this->firewall();
        $firewall->broken = true;

        $this->assertFalse((new ProxyRulePorts($firewall))->sync([$this->rule(7, 'tcp', '*', 25212)]));

        $this->assertSame([], $firewall->rules);
    }

    /**
     * @return array{id: int, transport: string, listen_ip: string, listen_port: int, upstream_host: string, upstream_port: int, is_generated: bool}
     */
    private function rule(int $id, string $transport, string $ip, int $port): array
    {
        return [
            'id' => $id,
            'transport' => $transport,
            'listen_ip' => $ip,
            'listen_port' => $port,
            'upstream_host' => 'tr12i',
            'upstream_port' => 3000,
            'is_generated' => false,
        ];
    }

    /** @param list<FirewallRule> $rules */
    private function firewall(array $rules = []): object
    {
        return new class ($rules) implements Firewall {
            /** @var array<string, FirewallRule> */
            public array $rules = [];
            public int $adds = 0;
            public bool $broken = false;

            /** @param list<FirewallRule> $rules */
            public function __construct(array $rules)
            {
                foreach ($rules as $rule) {
                    $this->rules[$rule->id()] = $rule;
                }
            }

            public function name(): string
            {
                return 'fake';
            }

            public function status(): FirewallStatus
            {
                return new FirewallStatus('fake', true);
            }

            public function rules(): array
            {
                if ($this->broken) {
                    throw new FirewallException('ufw is not installed');
                }

                return array_values($this->rules);
            }

            public function rule(string $id): FirewallRule
            {
                return $this->rules[$id] ?? throw FirewallNotFound::rule($id);
            }

            public function addRule(FirewallRule $rule): FirewallRule
            {
                $this->adds++;

                return $this->rules[$rule->id()] = $rule;
            }

            public function updateRule(string $id, FirewallRule $rule): FirewallRule
            {
                throw new \LogicException('not used');
            }

            public function deleteRule(string $id): FirewallRule
            {
                $rule = $this->rule($id);
                unset($this->rules[$id]);

                return $rule;
            }

            public function enable(): void
            {
            }

            public function disable(): void
            {
            }

            public function reload(): void
            {
            }

            public function logs(int $limit = 100, ?string $type = null, ?string $address = null): array
            {
                return [];
            }

            public function trustedAddresses(): array
            {
                return [];
            }

            public function trust(TrustedAddress $address): TrustedAddress
            {
                return $address;
            }

            public function untrust(string $id): TrustedAddress
            {
                throw new \LogicException('not used');
            }
        };
    }
}
