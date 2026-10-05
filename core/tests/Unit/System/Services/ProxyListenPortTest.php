<?php

namespace Tests\Unit\System\Services;

use App\Models\ProxyRule;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A proxy rule on a port something else on the host binds keeps every later
 * reload from applying, and the next sites-http restart from starting.
 */
class ProxyListenPortTest extends TestCase
{
    use InMemoryDatabase;

    /** Real `ss -Hln -t` lines from an engine host. */
    private const TCP = <<<'SS'
LISTEN 0      4096       127.0.0.54:53         0.0.0.0:*
LISTEN 0      4096   127.0.0.53%lo:53         0.0.0.0:*
LISTEN 0      4096          0.0.0.0:2011       0.0.0.0:*
LISTEN 0      80          127.0.0.1:3306       0.0.0.0:*
LISTEN 0      511          10.0.0.6:9000       0.0.0.0:*
LISTEN 0      4096             [::]:8443          [::]:*
LISTEN 0      4096                *:9100             *:*
LISTEN 0      511           0.0.0.0:8081       0.0.0.0:*
SS;

    private const UDP = <<<'SS'
UNCONN 0      0          127.0.0.54:53         0.0.0.0:*
UNCONN 0      0     [fe80::1%eth0]:546           [::]:*
SS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_webservers_ports_are_for_http_rules_only(): void
    {
        $ports = $this->ports();
        foreach ([80, 443] as $port) {
            $this->assertNull($ports->refusal('http', '*', $port));
            $this->assertSame("Port {$port} is the webserver's own; only an http rule can listen on it.", $ports->refusal('tcp', '*', $port));
            $this->assertNotNull($ports->refusal('udp', '10.0.0.5', $port));
        }
    }

    public function test_the_engines_own_ports_are_refused_whatever_listens(): void
    {
        $ports = $this->ports(tcp: '', udp: '');
        foreach ([22, 21, 2011, 2222, 30000, 30009] as $port) {
            foreach (['http', 'tcp', 'udp'] as $transport) {
                $this->assertSame("Port {$port} is reserved by the engine.", $ports->refusal($transport, '*', $port), "{$transport} {$port}");
            }
        }
    }

    public function test_a_port_bound_on_the_host_is_refused(): void
    {
        $ports = $this->ports();

        $this->assertSame('Port 3306 is already in use on this host.', $ports->refusal('tcp', '*', 3306));
        $this->assertSame('Port 9100 is already in use on this host.', $ports->refusal('http', '*', 9100));
        $this->assertSame('Port 53 is already in use on this host.', $ports->refusal('udp', '*', 53));
        $this->assertNull($ports->refusal('tcp', '*', 25212));
    }

    public function test_tcp_and_udp_are_separate_ports(): void
    {
        $ports = $this->ports();

        $this->assertNull($ports->refusal('udp', '*', 3306));
        $this->assertNull($ports->refusal('tcp', '*', 546));
    }

    public function test_an_address_collides_with_itself_and_the_wildcard_only(): void
    {
        $ports = $this->ports();

        // 10.0.0.6:9000 is bound.
        $this->assertNotNull($ports->refusal('tcp', '10.0.0.6', 9000));
        $this->assertNotNull($ports->refusal('tcp', '*', 9000));
        $this->assertNull($ports->refusal('tcp', '10.0.0.5', 9000));
        // [::]:8443 and *:9100 take every address.
        $this->assertNotNull($ports->refusal('tcp', '10.0.0.5', 8443));
        $this->assertNotNull($ports->refusal('tcp', '2a01:4f8::1', 9100));
        // An interface suffix is not part of the address.
        $this->assertNotNull($ports->refusal('udp', 'fe80::1', 546));
        $this->assertNull($ports->refusal('udp', '10.0.0.5', 53));
    }

    public function test_a_port_another_http_rule_holds_is_shared_by_http_rules_only(): void
    {
        // 8081 is bound on the host: by nginx, for this rule.
        $held = $this->rule('http', 8081);
        $ports = $this->ports();

        $this->assertNull($ports->refusal('http', '*', 8081));
        $this->assertNull($ports->refusal('http', '10.0.0.5', 8081));
        $this->assertSame("Port 8081 is already used by proxy rule {$held->id} (http).", $ports->refusal('tcp', '*', 8081));
        $this->assertNull($ports->refusal('udp', '*', 8081));
    }

    public function test_a_port_a_stream_rule_holds_is_shared_by_rules_of_its_kind(): void
    {
        $held = $this->rule('tcp', 25212);
        $ports = $this->ports(tcp: 'LISTEN 0 511 0.0.0.0:25212 0.0.0.0:*');

        $this->assertNull($ports->refusal('tcp', '10.0.0.5', 25212));
        $this->assertSame("Port 25212 is already used by proxy rule {$held->id} (tcp).", $ports->refusal('http', '*', 25212));
    }

    public function test_a_disabled_rule_holds_nothing(): void
    {
        $this->rule('http', 8081, enabled: false);

        // Whatever binds 8081 now is not nginx serving that rule.
        $this->assertSame('Port 8081 is already in use on this host.', $this->ports()->refusal('http', '*', 8081));
    }

    public function test_a_listing_that_cannot_be_read_refuses_nothing(): void
    {
        $system = new class extends System {
            public function execOnHost(string|array $cmd, array $env = []): string
            {
                throw new \RuntimeException('nsenter: permission denied');
            }
        };

        $this->assertNull((new ProxyListenPort($system))->refusal('tcp', '*', 3306));
        $this->assertNotNull((new ProxyListenPort($system))->refusal('tcp', '*', 2011));
    }

    public function test_the_listing_is_parsed_to_address_and_port(): void
    {
        $this->assertSame(
            [['127.0.0.54', 53], ['127.0.0.53', 53], ['0.0.0.0', 2011], ['127.0.0.1', 3306], ['10.0.0.6', 9000], ['::', 8443], ['*', 9100], ['0.0.0.0', 8081]],
            ProxyListenPort::listening(self::TCP)
        );
        $this->assertSame([['127.0.0.54', 53], ['fe80::1', 546]], ProxyListenPort::listening(self::UDP));
        $this->assertSame([], ProxyListenPort::listening(''));
    }

    private function ports(string $tcp = self::TCP, string $udp = self::UDP): ProxyListenPort
    {
        $system = new class ($tcp, $udp) extends System {
            public function __construct(private string $tcp, private string $udp)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $cmd = (array) $cmd;
                TestCase::assertSame(['ss', '-Hln'], array_slice($cmd, 0, 2));

                return end($cmd) === '-u' ? $this->udp : $this->tcp;
            }
        };

        return new ProxyListenPort($system);
    }

    private function rule(string $transport, int $port, bool $enabled = true): ProxyRule
    {
        return ProxyRule::query()->create([
            'owner_scope' => 'system',
            'transport' => $transport,
            'listen_ip' => '*',
            'listen_port' => $port,
            'upstream_host' => 'app',
            'upstream_port' => 3000,
            'enabled' => $enabled,
        ]);
    }
}
