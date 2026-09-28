<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\PmaSso;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * engine#48 item 19: the phpMyAdmin SSO exchange hands back MySQL credentials
 * and used to admit any private address -- which every tenant container on
 * pash-default-network has. Only the phpMyAdmin container may redeem now.
 */
class PmaSsoTest extends TestCase
{
    private const PMA = '172.20.0.5';
    private const TENANT = '172.20.0.9';

    /** @var list<string> */
    private array $pmaAddresses = [self::PMA];

    /** @var list<string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.phpmyadmin.sso_sources' => 'phpmyadmin-users.shared-hosting.palocal']);
    }

    public function test_the_phpmyadmin_container_is_admitted(): void
    {
        $this->assertSame(204, $this->answer(['PA_PEER_ADDR' => self::PMA, 'REMOTE_ADDR' => self::PMA]));
        $this->assertSame(['phpmyadmin-users.shared-hosting.palocal'], $this->lookups);
    }

    public function test_a_tenant_container_on_the_same_network_is_refused(): void
    {
        // Private, and on core's own network: exactly what the old rule let in.
        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => self::TENANT, 'REMOTE_ADDR' => self::TENANT]));
    }

    public function test_a_tenant_cannot_borrow_the_phpmyadmin_address_through_x_forwarded_for(): void
    {
        // realip trusts 172.16/12, so a tenant sending X-Forwarded-For: <pma>
        // arrives with REMOTE_ADDR rewritten to it. The peer nginx saw is not.
        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => self::TENANT, 'REMOTE_ADDR' => self::PMA]));
    }

    public function test_a_public_client_is_refused(): void
    {
        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => '8.8.8.8', 'REMOTE_ADDR' => '8.8.8.8']));
    }

    public function test_the_api_port_loopback_hop_is_refused(): void
    {
        // Anything through :2011 reaches PHP from the 127.0.0.1 proxy hop.
        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '203.0.113.7']));
    }

    public function test_nothing_is_admitted_when_phpmyadmin_does_not_resolve(): void
    {
        // The phpmyadmin profile is off: no container, no caller to admit.
        $this->pmaAddresses = [];

        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => self::PMA, 'REMOTE_ADDR' => self::PMA]));
    }

    public function test_the_address_is_resolved_per_request(): void
    {
        $this->assertSame(204, $this->answer(['PA_PEER_ADDR' => self::PMA]));

        // Container recreated with a new address.
        $this->pmaAddresses = ['172.20.0.44'];

        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => self::PMA]));
        $this->assertSame(204, $this->answer(['PA_PEER_ADDR' => '172.20.0.44']));
    }

    public function test_an_nginx_conf_without_the_peer_param_falls_back_to_remote_addr(): void
    {
        // Core's nginx.conf is a single-file bind mount; until core restarts
        // after an update it can be the old one. SSO must keep working then.
        $this->assertSame(204, $this->answer(['REMOTE_ADDR' => self::PMA]));
        $this->assertSame(404, $this->answer(['REMOTE_ADDR' => self::TENANT]));
    }

    public function test_a_range_restores_the_old_rule_for_an_operator_who_needs_it(): void
    {
        config(['services.phpmyadmin.sso_sources' => '172.16.0.0/12, 10.0.0.7']);

        $this->assertSame(204, $this->answer(['PA_PEER_ADDR' => self::TENANT]));
        $this->assertSame(204, $this->answer(['PA_PEER_ADDR' => '10.0.0.7']));
        $this->assertSame(404, $this->answer(['PA_PEER_ADDR' => '10.0.0.8']));
        $this->assertSame([], $this->lookups, 'addresses and ranges are not looked up');
    }

    public function test_core_nginx_hands_php_the_pre_realip_peer(): void
    {
        $path = __DIR__ . '/../../../../config/core/nginx.conf';
        if (!is_file($path)) {
            $this->markTestSkipped('config/core/nginx.conf is not beside core/ here');
        }
        $conf = (string) file_get_contents($path);

        $include = strpos($conf, 'include fastcgi_params;');
        $peer = strpos($conf, 'fastcgi_param PA_PEER_ADDR $realip_remote_addr;');
        $this->assertNotFalse($peer);
        $this->assertGreaterThan($include, $peer);
    }

    /**
     * @param array<string, string> $server
     */
    private function answer(array $server): int
    {
        $request = Request::create('/api/mysql/phpmyadmin-sso-token', 'PUT', [], [], [], $server + ['REMOTE_ADDR' => '127.0.0.1']);

        $test = $this;
        $middleware = new class ($test) extends PmaSso {
            public function __construct(private PmaSsoTest $test)
            {
            }

            protected function resolve(string $hostname): array
            {
                return $this->test->lookup($hostname);
            }
        };

        try {
            return $middleware->handle($request, static fn (Request $r) => new JsonResponse(null, 204))->getStatusCode();
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getStatusCode();
        }
    }

    /**
     * @return list<string>
     */
    public function lookup(string $hostname): array
    {
        $this->lookups[] = $hostname;

        return $this->pmaAddresses;
    }
}
