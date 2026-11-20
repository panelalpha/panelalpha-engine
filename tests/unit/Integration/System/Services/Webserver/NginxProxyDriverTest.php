<?php

namespace Tests\Integration\System\Services\Webserver;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\ProxyRulePorts;
use Illuminate\Support\Facades\Log;
use Tests\Integration\System\Webserver\WebserverHostDouble;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The nginx-proxy driver against an in-memory host: the vhosts, the main and app-lite configs, the
 * stream file and the firewall marker it writes, and the commands it runs around them.
 */
class NginxProxyDriverTest extends TestCase
{
    use InMemoryDatabase;

    private const VHOSTS = '/opt/engine/webserver-config/nginx-proxy/vhosts';

    private const MAIN_CONFIG = '/opt/engine/webserver-config/nginx-proxy/nginx.conf';

    private const MAIN_TEMPLATE = '/opt/engine/templates/webserver-nginx-proxy.blade.php';

    private const APP_LITE_TEMPLATE = '/opt/engine/templates/virtualHost-nginx-proxy-app-lite.blade.php';

    private const APP_LITE_CONFIG = self::VHOSTS.'/app-lite.conf';

    private const STREAM_CONFIG = '/opt/engine/webserver-config/nginx-proxy/stream.conf';

    private const PORTS_MARKER = '/opt/engine/webserver-config/nginx-proxy/proxy-rule-ports';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2]);
        config(['env.APP_LITE_PROXY_ENABLED' => false]);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
        $this->host->files[self::MAIN_TEMPLATE] = (string) file_get_contents(dirname(base_path()).'/templates/webserver-nginx-proxy.blade.php');
        $this->currentWebserver('nginx-proxy');
    }

    protected function tearDown(): void
    {
        $this->currentWebserver(null);
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_details_name_the_nginx_proxy_driver(): void
    {
        $this->assertSame(
            ['name' => 'Nginx + Apache', 'version' => 'Unknown', 'slug' => 'nginx-proxy', 'metadata' => []],
            $this->proxy()->getDetails()
        );
    }

    public function test_a_classic_domain_gets_its_vhost_with_its_certificate_and_a_logs_directory(): void
    {
        $domain = $this->domain('alice', 'alice.test', ['aliases' => ['www.alice.test']]);
        $certs = $this->host->projectDirPath('alice').'/ssl-certs';
        $this->host->files["{$certs}/alice.test.pem"] = 'pem';
        $this->host->files["{$certs}/alice.test.key"] = 'key';

        $this->proxy()->addDomain($domain);

        $vhost = $this->host->files[self::VHOSTS.'/alice.test.conf'];
        $this->assertStringContainsString('server_name  alice.test www.alice.test', $vhost);
        $this->assertStringContainsString("ssl_certificate {$certs}/alice.test.pem;", $vhost);
        $this->assertStringContainsString("ssl_certificate_key {$certs}/alice.test.key;", $vhost);
        $this->assertStringContainsString('webserver-logs/nginx-proxy/alice.test', $this->host->snapshot());
    }

    public function test_a_rebuilt_domain_without_ssl_has_no_tls_block(): void
    {
        $domain = $this->domain('alice', 'alice.test', ['ssl_disabled' => true]);

        $this->proxy()->rebuildDomainConfig($domain);

        $this->assertStringNotContainsString('ssl_certificate ', $this->host->files[self::VHOSTS.'/alice.test.conf']);
    }

    public function test_a_redirect_url_is_rendered_into_the_vhost(): void
    {
        $domain = $this->domain('alice', 'alice.test', ['redirect_enabled' => true, 'redirect_url' => 'https://shop.example.test/landing']);

        $this->proxy()->addDomain($domain);

        $this->assertStringContainsString('return 301 "https://shop.example.test/landing";', $this->host->files[self::VHOSTS.'/alice.test.conf']);
    }

    public function test_a_redirect_url_with_nginx_metacharacters_is_dropped_and_logged(): void
    {
        Log::spy();
        $domain = $this->domain('alice', 'alice.test', ['redirect_enabled' => true, 'redirect_url' => 'https://shop.example.test/"; return 200 "x']);

        $this->proxy()->addDomain($domain);

        $this->assertStringNotContainsString('return 301', $this->host->files[self::VHOSTS.'/alice.test.conf']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'nginx metacharacters'));
    }

    public function test_a_dind_domain_listens_where_its_rules_say(): void
    {
        $domain = $this->domain('alice', 'alice.test', [], ['template' => 'dind', 'app_port' => 3000]);
        $certs = $this->host->projectDirPath('alice').'/ssl-certs';
        $this->host->files["{$certs}/alice.test.pem"] = 'pem';
        $this->host->files["{$certs}/alice.test.key"] = 'key';
        $this->rule(80, 'alice', '2001:db8::5', 'alice.test');
        $this->rule(443, 'alice', TestNetwork::private(9), 'alice.test');
        $this->rule(8443, 'alice', '*', 'alice.test');

        $this->proxy()->addDomain($domain);

        $vhost = $this->host->files[self::VHOSTS.'/alice.test.conf'];
        $this->assertStringContainsString('listen [2001:db8::5]:80', $vhost);
        $this->assertStringContainsString('listen '.TestNetwork::private(9).':443', $vhost);
        $this->assertStringContainsString('listen '.TestNetwork::DOCUMENTATION_2.':8443', $vhost);
        $this->assertStringContainsString('set $proxyupstream alice;', $vhost);
    }

    public function test_every_named_domain_config_is_removed(): void
    {
        $this->proxy()->deleteDomainsConfigs(['alice.test', 'bob.test']);

        $snapshot = $this->host->snapshot();
        $this->assertStringContainsString(self::VHOSTS.'/alice.test.conf', $snapshot);
        $this->assertStringContainsString(self::VHOSTS.'/bob.test.conf', $snapshot);
        $this->assertSame(2, substr_count($snapshot, 'exec '));
    }

    public function test_a_missing_main_config_needs_a_rebind(): void
    {
        $this->assertTrue($this->proxy()->needsIpListenerRebind());
    }

    public function test_wildcard_default_listens_need_a_rebind(): void
    {
        $this->host->files[self::MAIN_CONFIG] = "server {\n    listen 80 default_server;\n}\n";
        $this->assertTrue($this->proxy()->needsIpListenerRebind());

        $this->host->files[self::MAIN_CONFIG] = "server {\n    listen 443 ssl;\n}\n";
        $this->assertTrue($this->proxy()->needsIpListenerRebind());
    }

    public function test_address_bound_listens_need_no_rebind(): void
    {
        $address = TestNetwork::private(4);
        $this->host->files[self::MAIN_CONFIG] = "server {\n    listen {$address}:80 default_server;\n    listen {$address}:443 ssl;\n}\n";

        $this->assertFalse($this->proxy()->needsIpListenerRebind());
    }

    public function test_a_rebind_reload_and_a_restart_restart_the_container_from_the_host(): void
    {
        $this->proxy()->reload(true);
        $this->proxy()->restart();

        $snapshot = $this->host->snapshot();
        $this->assertSame(2, substr_count($snapshot, '"compose.restart","--","/opt/engine/docker-compose.yml","sites-http"'));
        $this->assertStringNotContainsString('nginxReload', $snapshot);
    }

    public function test_toggling_modsecurity_renders_the_main_config_and_restarts(): void
    {
        $this->proxy()->toggleModsecurity();

        $this->assertArrayHasKey(self::MAIN_CONFIG, $this->host->files);
        $this->assertLessThan(
            (int) strpos($this->host->snapshot(), '"compose.restart"'),
            (int) strpos($this->host->snapshot(), 'put '.self::MAIN_CONFIG)
        );
    }

    public function test_a_full_rebuild_writes_every_config_and_opens_the_custom_ports(): void
    {
        $domain = $this->domain('alice', 'alice.test', ['ssl_disabled' => true]);
        $this->systemRule('tcp', 5432, null);
        $this->systemRule('http', 8080, '*.alice.test');
        $proxy = $this->proxy();

        $proxy->rebuildConfig([$domain]);

        $this->assertArrayHasKey(self::MAIN_CONFIG, $this->host->files);
        $this->assertArrayHasKey(self::VHOSTS.'/alice.test.conf', $this->host->files);
        $this->assertArrayHasKey(self::VHOSTS.'/proxy-rule-8080___alice_test.conf', $this->host->files);
        $this->assertStringContainsString('proxy_pass stream_proxy_', $this->host->files[self::STREAM_CONFIG]);
        $this->assertStringContainsString('listen 5432;', $this->host->files[self::STREAM_CONFIG]);
        $this->assertSame([[80, 5432, 8080]], array_map(fn (array $rules): array => $this->ports($rules), $proxy->ports->synced));
        $this->assertSame($this->marker($proxy->ports->synced[0]), $this->host->files[self::PORTS_MARKER]);
        $this->assertStringContainsString('app-lite.conf', $this->host->snapshot());
        $this->assertArrayNotHasKey(self::APP_LITE_CONFIG, $this->host->files);
    }

    public function test_a_rebuild_without_custom_ports_leaves_the_firewall_alone(): void
    {
        $domain = $this->domain('alice', 'alice.test', ['ssl_disabled' => true]);
        $proxy = $this->proxy();

        $proxy->rebuildConfig([$domain]);

        $this->assertSame([], $proxy->ports->synced);
        $this->assertArrayNotHasKey(self::PORTS_MARKER, $this->host->files);
        $this->assertSame("# Stream (TCP/UDP) rules\n", $this->host->files[self::STREAM_CONFIG]);
    }

    public function test_a_failed_firewall_sync_leaves_the_marker_unwritten(): void
    {
        $this->systemRule('udp', 5353, null);
        $proxy = $this->proxy(syncs: false);

        $proxy->rebuildConfig([]);

        $this->assertCount(1, $proxy->ports->synced);
        $this->assertArrayNotHasKey(self::PORTS_MARKER, $this->host->files);
        $this->assertStringContainsString('listen 5353 udp;', $this->host->files[self::STREAM_CONFIG]);
    }

    public function test_the_app_lite_vhost_is_rendered_from_its_template_when_the_proxy_is_on(): void
    {
        config(['env.APP_LITE_PROXY_ENABLED' => true, 'env.APP_LITE_CA_PORT' => 8443]);
        $this->host->files[self::APP_LITE_TEMPLATE] = "ca {{ \$ca_port }}\n@foreach (\$ips_v4 as \$ip)listen {{ \$ip }}\n@endforeach\ncert {{ \$ssl_cert_file }}\n";

        $this->proxy()->rebuildConfig([]);

        $appLite = $this->host->files[self::APP_LITE_CONFIG];
        $this->assertStringContainsString('ca 8443', $appLite);
        $this->assertStringContainsString('listen '.TestNetwork::DOCUMENTATION_2, $appLite);
        $this->assertStringContainsString('webserver-logs/nginx-proxy/app-lite', $this->host->snapshot());
    }

    public function test_the_app_lite_proxy_without_its_legacy_template_writes_no_vhost(): void
    {
        config(['env.APP_LITE_PROXY_ENABLED' => true]);

        $this->proxy()->rebuildConfig([]);

        $this->assertArrayNotHasKey(self::APP_LITE_CONFIG, $this->host->files);
        $this->assertStringContainsString('webserver-logs/nginx-proxy/app-lite', $this->host->snapshot());
    }

    public function test_applying_https_ports_opens_each_extra_port_a_domain_answers_on(): void
    {
        $this->domain('alice', 'alice.test', ['https_ports' => [443, 8443]]);
        $proxy = $this->proxy();

        $proxy->applyHttpsPorts();

        $this->assertStringContainsString('8443', $this->host->files[self::MAIN_CONFIG]);
        $this->assertSame([[8443]], array_map(fn (array $rules): array => $this->ports($rules), $proxy->ports->synced));
        $this->assertSame('https-8443', $proxy->ports->synced[0][0]['id']);
        $this->assertSame($this->marker($proxy->ports->synced[0]), $this->host->files[self::PORTS_MARKER]);
    }

    public function test_the_shipped_firewall_sync_is_a_proxy_rule_ports(): void
    {
        $proxy = new class ($this->host) extends NginxProxy {
            public function ports(): ProxyRulePorts
            {
                return $this->proxyRulePorts();
            }
        };

        $this->assertInstanceOf(ProxyRulePorts::class, $proxy->ports());
    }

    public function test_rules_whose_names_differ_only_in_case_share_one_vhost(): void
    {
        $proxy = $this->proxy();

        $proxy->render([
            $this->httpRule(1, 'Shop.example.test'),
            $this->httpRule(2, 'shop.example.test'),
            $this->httpRule(3, 'shop.example.test'),
        ]);

        $vhost = $this->host->files[self::VHOSTS.'/proxy-rule-80_shop_example_test.conf'];
        $this->assertStringContainsString('server_name Shop.example.test shop.example.test;', $vhost);
    }

    public function test_a_config_without_port_80_is_checked_on_443_alone_whatever_else_it_listens_on(): void
    {
        $address = TestNetwork::private(4);
        $this->host->files[self::MAIN_CONFIG] = "server {\n    listen {$address}:443 ssl;\n    listen 8080;\n}\n";

        $this->assertFalse($this->proxy()->listenersMatchConfig());
    }

    private function currentWebserver(?string $slug): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $slug);
    }

    /** A driver whose firewall sync is recorded instead of run. */
    private function proxy(bool $syncs = true): NginxProxy
    {
        $ports = new class ($syncs) extends ProxyRulePorts {
            /** @var list<list<array<string, mixed>>> */
            public array $synced = [];

            public function __construct(private bool $syncs)
            {
                parent::__construct();
            }

            public function sync(array $rules): bool
            {
                $this->synced[] = $rules;

                return $this->syncs;
            }
        };

        return new class ($this->host, $ports) extends NginxProxy {
            public function __construct(WebserverHostDouble $host, public ProxyRulePorts $ports)
            {
                parent::__construct($host);
            }

            protected function proxyRulePorts(): ProxyRulePorts
            {
                return $this->ports;
            }

            protected function pauseBeforeRecheck(): void {}

            /** @param list<array<string, mixed>> $rules */
            public function render(array $rules): void
            {
                $this->renderProxyRuleVhosts($rules, false);
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<int>
     */
    private function ports(array $rules): array
    {
        $ports = array_map(static fn (array $rule): int => (int) $rule['listen_port'], $rules);
        sort($ports);

        return $ports;
    }

    /** @param list<array<string, mixed>> $rules */
    private function marker(array $rules): string
    {
        $ids = array_keys(ProxyRulePorts::allows($rules));
        sort($ids);
        $this->assertNotSame([], $ids);

        return implode("\n", $ids)."\n";
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed> $userDetails
     */
    private function domain(string $username, string $name, array $details, array $userDetails = []): Domain
    {
        $domain = $this->makeMainDomain($this->makeUser($username, $userDetails, $name), $name);
        $domain->setDetails($details);
        $domain->save();

        return $domain;
    }

    private function rule(int $port, string $username, string $listenIp, string $serverName): void
    {
        ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $username, 'enabled' => true, 'transport' => 'http',
            'listen_ip' => $listenIp, 'listen_port' => $port, 'server_name' => $serverName,
            'upstream_host' => $username, 'upstream_port' => 8080, 'upstream_protocol' => 'http', 'is_generated' => false,
        ]);
    }

    private function systemRule(string $transport, int $port, ?string $serverName): void
    {
        ProxyRule::query()->create([
            'owner_scope' => 'system', 'username' => null, 'enabled' => true, 'transport' => $transport,
            'listen_ip' => '*', 'listen_port' => $port, 'server_name' => $serverName,
            'upstream_host' => 'backend', 'upstream_port' => 9000, 'upstream_protocol' => 'http', 'is_generated' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function httpRule(int $id, string $serverName): array
    {
        return [
            'id' => $id, 'transport' => 'http', 'listen_ip' => '*', 'listen_port' => 80, 'server_name' => $serverName,
            'upstream_host' => 'backend', 'upstream_port' => 3000, 'upstream_protocol' => 'http', 'is_generated' => false,
        ];
    }
}
