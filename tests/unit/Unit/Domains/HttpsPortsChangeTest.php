<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Which ports a container domain may move its HTTPS to: 443 always, any other with the operator's permission and only
 * where nothing but another domain's HTTPS listens.
 */
class HttpsPortsChangeTest extends TestCase
{
    use InMemoryDatabase;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null]);
        $this->system = $this->hostListening('');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_443_never_needs_the_operator(): void
    {
        $this->assertNull($this->change()->refusal($this->domain('shop'), [443], 443));
    }

    public function test_another_port_needs_the_operators_permission(): void
    {
        $domain = $this->domain('shop');

        $this->assertSame(HttpsPortsChange::NOT_ALLOWED, $this->change()->refusal($domain, [443, 8443], 443)['code'] ?? null);

        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::PORTS]);
        $this->assertNull($this->change()->refusal($domain, [443, 8443], 8443));
    }

    public function test_only_a_container_project_with_ssl_moves(): void
    {
        $this->assertSame('https_ports_container_only', $this->change()->refusal($this->domain('blog', ['template' => 'wordpress']), [443], 443)['code'] ?? null);

        $plain = $this->domain('plain');
        $plain->setDetails(['ssl_disabled' => true]);
        $plain->save();
        $this->assertSame('https_ports_need_ssl', $this->change()->refusal($plain, [443], 443)['code'] ?? null);
    }

    public function test_a_name_a_tunnel_brings_in_keeps_443(): void
    {
        $this->allowPorts('shop');
        $domain = $this->makeMainDomain($this->makeUser('shop', ['template' => 'dind'], 'shop.panelalpha.online'), 'shop.panelalpha.online');

        $this->assertSame('https_ports_tunnelled', $this->change()->refusal($domain, [8443], 8443)['code'] ?? null);
        $this->assertNull($this->change()->refusal($domain, [443, 8443], 443));
    }

    public function test_the_main_port_has_to_be_one_of_the_ports(): void
    {
        $refusal = $this->change()->refusal($this->domain('shop'), [443], 8443);

        $this->assertSame(['field' => HttpsPorts::MAIN_KEY, 'code' => 'https_port_not_listed'], array_slice($refusal ?? [], 0, 2, true));
    }

    public function test_the_webservers_and_the_engines_own_ports_are_refused(): void
    {
        $this->allowPorts('shop');
        $domain = $this->domain('shop');

        $this->assertSame('https_port_webserver', $this->change()->refusal($domain, [80], 80)['code'] ?? null);
        $this->assertSame('https_port_reserved', $this->change()->refusal($domain, [2011], 2011)['code'] ?? null);
        $this->assertSame('https_ports_invalid', $this->change()->refusal($domain, [70000], 70000)['code'] ?? null);
    }

    public function test_a_port_a_proxy_rule_or_the_host_holds_is_refused(): void
    {
        $this->allowPorts('shop');
        $domain = $this->domain('shop');
        $this->makeUser('other');
        $this->rule('tcp', 8443);
        $this->rule('http', 8080);

        $this->assertSame('Port 8443 is used by proxy rule 1 (tcp).', $this->change()->refusal($domain, [8443], 8443)['message'] ?? null);
        $this->assertSame('Port 8080 is used by proxy rule 2 (http).', $this->change()->refusal($domain, [8080], 8080)['message'] ?? null);
        $this->assertNull($this->change()->refusal($domain, [9443], 9443));

        $this->system = $this->hostListening('LISTEN 0 4096 0.0.0.0:9443 0.0.0.0:*');
        $this->assertSame('Port 9443 is already in use on this host.', $this->change()->refusal($domain, [9443], 9443)['message'] ?? null);
    }

    public function test_domains_share_a_port_by_name_as_on_443(): void
    {
        $this->allowPorts('shop');
        $blog = $this->domain('blog');
        $blog->setDetails([HttpsPorts::PORTS_KEY => [8443], HttpsPorts::MAIN_KEY => 8443]);
        $blog->save();
        $this->system = $this->hostListening('LISTEN 0 4096 0.0.0.0:8443 0.0.0.0:*');

        $this->assertNull($this->change()->refusal($this->domain('shop'), [443, 8443], 443));
    }

    public function test_a_proxy_rule_may_not_take_a_port_a_domain_answers_https_on(): void
    {
        $blog = $this->domain('blog');
        $blog->setDetails([HttpsPorts::PORTS_KEY => [8443], HttpsPorts::MAIN_KEY => 8443]);
        $blog->save();
        $ports = new ProxyListenPort($this->system);

        $this->assertSame('Port 8443 carries HTTPS for blog.example.test.', $ports->refusal('tcp', '*', 8443));
        $this->assertSame('Port 8443 carries HTTPS for blog.example.test.', $ports->refusal('http', '*', 8443));
        $this->assertNull($ports->refusal('udp', '*', 8443));
    }

    private function change(): HttpsPortsChange
    {
        return new HttpsPortsChange($this->system, new PublicRoutingGrants);
    }

    private function allowPorts(string $username): void
    {
        (new PublicRoutingGrants)->allow($username, [PublicRoutingGrants::PORTS]);
    }

    /** @param array<string, mixed> $details */
    private function domain(string $username, array $details = ['template' => 'dind']): Domain
    {
        return $this->makeMainDomain($this->makeUser($username, $details, "{$username}.example.test"), "{$username}.example.test");
    }

    private function rule(string $transport, int $port): void
    {
        ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'other',
            'transport' => $transport,
            'listen_ip' => '*',
            'listen_port' => $port,
            'server_name' => $transport === 'http' ? 'other.example.test' : null,
            'upstream_host' => 'other',
            'upstream_port' => 3000,
            'enabled' => true,
        ]);
    }

    private function hostListening(string $tcp): System
    {
        return new class ($tcp) extends System {
            public function __construct(private string $tcp) {}

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->tcp;
            }
        };
    }
}
