<?php

namespace Tests\Unit\System\Services;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Filesystem;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\Ufw\UfwFirewall;
use App\System\Firewall\Ufw\UfwRules;
use App\System\ProcessRunner;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\ProxyRulePorts;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
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

    public function test_an_operators_deny_keeps_its_port_closed_and_the_other_ports_still_open(): void
    {
        // ufw would turn the deny into the engine's allow; ufw's provider refuses instead.
        $closed = 'comment=' . bin2hex('closed');
        $denied = "### tuple ### deny tcp 25212 0.0.0.0/0 any 0.0.0.0/0 in {$closed}\n### tuple ### route:deny tcp 25212 0.0.0.0/0 any 0.0.0.0/0 in {$closed}\n";
        $deny = FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '25212']);
        $host = $this->ufwHost($denied);
        $rules = [$this->rule(7, 'tcp', '*', 25212), $this->rule(8, 'tcp', '*', 25300)];
        $lock = (string) tempnam(sys_get_temp_dir(), 'ufw-lock');
        Log::spy();

        try {
            $ports = new ProxyRulePorts(new UfwFirewall($host, $lock));
            $synced = [$ports->sync($rules), $ports->sync($rules)];
            $kept = str_starts_with($host->tuples, $denied);
            // The operator deletes the deny: the next sync opens that port too.
            $host->tuples = str_replace($denied, '', $host->tuples);
            $synced[] = $ports->sync($rules);
        } finally {
            @unlink($lock);
        }

        // A refused port is handled, not failed: the marker still records the ports opened beside it.
        $this->assertSame([true, true, true], $synced);
        $this->assertTrue($kept, 'the deny is left as it is');
        $this->assertSame([
            ['ufw', 'allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '25300', 'comment', 'panelalpha: proxy rule 8'],
            ['ufw', 'allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '25212', 'comment', 'panelalpha: proxy rule 7'],
        ], $host->ufw);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "Rule {$deny->id()} (deny in tcp 25212 on host and published ports)"))->twice();
    }

    public function test_rules_removed_in_one_rebuild_close_the_ports_opened_beside_a_refused_one(): void
    {
        $closed = 'comment=' . bin2hex('closed');
        $denied = "### tuple ### deny tcp 25212 0.0.0.0/0 any 0.0.0.0/0 in {$closed}\n### tuple ### route:deny tcp 25212 0.0.0.0/0 any 0.0.0.0/0 in {$closed}\n";
        $host = $this->ufwHost($denied);
        $lock = (string) tempnam(sys_get_temp_dir(), 'ufw-lock');
        Log::spy();

        try {
            [$proxy, $files] = $this->proxy(new ProxyRulePorts(new UfwFirewall($host, $lock)));
            $proxy->syncProxyRulePorts([$this->rule(7, 'tcp', '*', 25212), $this->rule(8, 'tcp', '*', 25300)]);
            // The project goes, and both of its proxy rules with it.
            $proxy->syncProxyRulePorts([]);
        } finally {
            @unlink($lock);
        }

        $this->assertSame($denied, $host->tuples, 'only the deny is left, as the operator wrote it');
        $this->assertSame([
            ['ufw', 'allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '25300', 'comment', 'panelalpha: proxy rule 8'],
            ['ufw', 'delete', 'allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '25300'],
        ], $host->ufw);
        $this->assertSame("\n", $files['/opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/proxy-rule-ports']);
    }

    public function test_a_firewall_that_fails_on_an_add_is_left_until_the_next_sync(): void
    {
        // Only a clash moves on to the next port: a lock held elsewhere costs one wait, not one per port.
        $firewall = $this->firewall();
        $firewall->failAdd = new FirewallException('Another firewall change held /opt/panelalpha/shared-hosting/data/ufw.lock for over 30 s; nothing was changed');

        $this->assertFalse((new ProxyRulePorts($firewall))->sync([$this->rule(7, 'tcp', '*', 25212), $this->rule(8, 'tcp', '*', 25300)]));
        $this->assertSame(1, $firewall->adds);
    }

    /** A host whose ufw holds $tuples, and adds and deletes allows written as the proxy rules' allows are. */
    private function ufwHost(string $tuples): object
    {
        return new class ($tuples) implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ufw = [];

            public function __construct(public string $tuples)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                if ($cmd === ['cat', '/etc/ufw/user.rules']) {
                    return FakeProcess::ok($this->tuples);
                }
                if ($cmd[0] !== 'ufw') {
                    return FakeProcess::failed('No such file');
                }
                // ufw [delete] allow in proto <proto> from any to any port <port> [comment <comment>]
                $this->ufw[] = $cmd;
                $after = static fn (string $word): string => $cmd[(int) array_search($word, $cmd, true) + 1];
                if ($cmd[1] === 'delete') {
                    $this->tuples = (string) preg_replace(sprintf('/^### tuple ### allow %s %s .*\n/m', $after('proto'), $after('port')), '', $this->tuples);

                    return FakeProcess::ok('Rule deleted');
                }
                $this->tuples .= sprintf("### tuple ### allow %s %s 0.0.0.0/0 any 0.0.0.0/0 in comment=%s\n", $after('proto'), $after('port'), bin2hex($after('comment')));

                return FakeProcess::ok('Rule added');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::ok();
            }
        };
    }

    /**
     * The webserver's sync of the proxy rule ports, with its marker file kept in memory.
     *
     * @return array{NginxProxy, \ArrayObject<string, string>}
     */
    private function proxy(ProxyRulePorts $ports): array
    {
        $files = new \ArrayObject();
        $system = new class ($files) extends System {
            /** @param \ArrayObject<string, string> $files */
            public function __construct(private \ArrayObject $files)
            {
            }

            public function engineDirPath(): string
            {
                return '/opt/panelalpha/shared-hosting';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->files) extends Filesystem {
                    /** @param \ArrayObject<string, string> $files */
                    public function __construct(System $system, private \ArrayObject $files)
                    {
                        parent::__construct($system);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? throw new \RuntimeException("cp: cannot stat '{$path}'");
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->files[$path] = $contents;
                    }
                };
            }
        };

        $proxy = new class ($system, $ports) extends NginxProxy {
            public function __construct(System $system, private ProxyRulePorts $ports)
            {
                parent::__construct($system);
            }

            protected function proxyRulePorts(): ProxyRulePorts
            {
                return $this->ports;
            }
        };

        return [$proxy, $files];
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
            public ?FirewallException $failAdd = null;

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
                if ($this->failAdd !== null) {
                    throw $this->failAdd;
                }

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
