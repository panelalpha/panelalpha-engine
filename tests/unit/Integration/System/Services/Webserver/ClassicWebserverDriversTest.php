<?php

namespace Tests\Integration\System\Services\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\System\Services\Webserver;
use App\System\Services\Webserver\Apache;
use App\System\Services\Webserver\Nginx;
use Tests\Integration\System\Webserver\WebserverHostDouble;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The Apache and Nginx drivers against an in-memory host: the main and app-lite configs they render,
 * how they reload, restart and toggle ModSecurity, and which vhosts they remove.
 */
class ClassicWebserverDriversTest extends TestCase
{
    use InMemoryDatabase;

    private const NGINX = '/opt/engine/webserver-config/nginx';

    private const APACHE = '/opt/engine/webserver-config/apache';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::DOCUMENTATION_2,
            'modsec' => json_encode(['mode' => 'on', 'enabled_rulesets' => []]),
        ]);
        config(['env.APP_LITE_PROXY_ENABLED' => false]);
        $templates = dirname(base_path()).'/templates';
        $this->host = new WebserverHostDouble($templates);
        foreach (['webserver-nginx', 'webserver-apache', 'virtualHost-nginx-app-lite', 'virtualHost-apache-app-lite'] as $name) {
            $this->host->files["/opt/engine/templates/{$name}.blade.php"] = (string) file_get_contents("{$templates}/{$name}.blade.php");
        }
        putenv('PA_ROOT_LOCAL_ROOTS={"engine":"/opt/engine"}');
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_details_name_each_driver(): void
    {
        $this->assertSame(['name' => 'Nginx', 'version' => 'Unknown', 'slug' => 'nginx', 'metadata' => []], (new Nginx($this->host))->getDetails());
        $this->assertSame(['name' => 'Apache', 'version' => 'Unknown', 'slug' => 'apache', 'metadata' => []], (new Apache($this->host))->getDetails());
    }

    public function test_an_nginx_rebuild_renders_the_main_config_the_script_and_every_vhost_and_drops_app_lite(): void
    {
        $this->currentWebserver('nginx');
        $domain = $this->domain('alice.test');

        (new Nginx($this->host))->rebuildConfig([$domain]);

        $main = $this->host->files[self::NGINX.'/nginx.conf'];
        $this->assertStringContainsString('load_module modules/ngx_http_modsecurity_module.so;', $main);
        $this->assertStringContainsString('listen '.TestNetwork::DOCUMENTATION_2.':80', $main);
        $this->assertSame(
            file_get_contents(dirname(base_path()).'/templates/webserver-config/nginx/njs/cookie-isolation.js'),
            $this->host->files[self::NGINX.'/njs/cookie-isolation.js']
        );
        $this->assertArrayHasKey(self::NGINX.'/vhosts/alice.test.conf', $this->host->files);
        $this->assertContains(['files.removeIfExists', [self::NGINX.'/vhosts/app-lite.conf']], $this->requests());
    }

    public function test_an_nginx_rebuild_with_the_app_lite_proxy_renders_its_vhost(): void
    {
        $this->currentWebserver('nginx');
        $this->appLite();

        (new Nginx($this->host))->rebuildConfig([]);

        $appLite = $this->host->files[self::NGINX.'/vhosts/app-lite.conf'];
        $this->assertStringContainsString('server panel-client:9090 resolve;', $appLite);
        $this->assertStringContainsString('listen '.TestNetwork::DOCUMENTATION_2.':8443 ssl;', $appLite);
        $this->assertContains(['files.makeDirectories', ['/opt/engine/webserver-logs/nginx/app-lite']], $this->requests());
    }

    public function test_a_gated_nginx_vhost_renders_a_main_config_that_is_not_there_yet(): void
    {
        (new Nginx($this->host))->createDomainConfig($this->domain('shop-4f2a.panelalpha.online'));

        $this->assertStringContainsString('js_import pa_cookie_isolation', $this->host->files[self::NGINX.'/nginx.conf']);
    }

    public function test_nginx_needs_a_rebind_while_its_main_config_is_missing_or_on_wildcards(): void
    {
        $nginx = new Nginx($this->host);
        $this->assertTrue($nginx->needsIpListenerRebind());

        $this->host->files[self::NGINX.'/nginx.conf'] = "server {\n    listen 80;\n}\n";
        $this->assertTrue($nginx->needsIpListenerRebind());

        $this->host->files[self::NGINX.'/nginx.conf'] = "server {\n    listen 443 ssl default_server;\n}\n";
        $this->assertTrue($nginx->needsIpListenerRebind());

        $address = TestNetwork::DOCUMENTATION_2;
        $this->host->files[self::NGINX.'/nginx.conf'] = "server {\n    listen {$address}:80;\n    listen {$address}:443 ssl;\n}\n";
        $this->assertFalse($nginx->needsIpListenerRebind());
    }

    public function test_nginx_restarts_the_container_for_a_rebind_a_restart_and_a_modsecurity_toggle(): void
    {
        $nginx = new Nginx($this->host);

        $nginx->reload(true);
        $nginx->restart();
        $nginx->toggleModsecurity();

        $actions = array_column($this->requests(), 0);
        $this->assertSame(3, count(array_keys($actions, 'compose.restart', true)));
        $this->assertNotContains('compose.nginxReload', $actions);
        $this->assertArrayHasKey(self::NGINX.'/nginx.conf', $this->host->files);
    }

    public function test_nginx_removes_each_named_vhost(): void
    {
        $nginx = new Nginx($this->host);

        $nginx->deleteDomainConfig('alice.test');
        $nginx->deleteDomainsConfigs(['bob.test', 'carol.test']);

        $this->assertSame([
            ['files.removeIfExists', [self::NGINX.'/vhosts/alice.test.conf']],
            ['files.removeIfExists', [self::NGINX.'/vhosts/bob.test.conf']],
            ['files.removeIfExists', [self::NGINX.'/vhosts/carol.test.conf']],
        ], $this->requests());
    }

    public function test_an_apache_rebuild_renders_the_main_config_and_every_vhost_and_drops_app_lite(): void
    {
        $this->currentWebserver('apache');
        $domain = $this->domain('alice.test');

        (new Apache($this->host))->rebuildConfig([$domain]);

        $main = $this->host->files[self::APACHE.'/httpd.conf'];
        $this->assertStringContainsString('LoadModule security3_module', $main);
        $this->assertArrayHasKey(self::APACHE.'/vhosts/alice.test.conf', $this->host->files);
        $this->assertContains(['files.removeIfExists', [self::APACHE.'/vhosts/app-lite.conf']], $this->requests());
    }

    public function test_an_apache_rebuild_with_the_app_lite_proxy_renders_its_vhost(): void
    {
        $this->currentWebserver('apache');
        $this->appLite();

        (new Apache($this->host))->rebuildConfig([]);

        $appLite = $this->host->files[self::APACHE.'/vhosts/app-lite.conf'];
        $this->assertStringContainsString('Listen 8443', $appLite);
        $this->assertStringContainsString('ProxyPass / http://panel-client:9090/', $appLite);
        $this->assertContains(['files.makeDirectories', ['/opt/engine/webserver-logs/apache/app-lite']], $this->requests());
    }

    public function test_apache_reloads_gracefully_restarts_and_toggles_modsecurity_with_a_restart(): void
    {
        $apache = new Apache($this->host);

        $apache->reload(true);
        $apache->restart();
        $apache->toggleModsecurity();

        $this->assertSame(
            ['compose.apacheGraceful', 'compose.apacheRestart', 'compose.apacheRestart'],
            array_values(array_filter(array_column($this->requests(), 0), static fn (?string $action): bool => str_starts_with((string) $action, 'compose.')))
        );
        $this->assertStringContainsString('LoadModule security3_module', $this->host->files[self::APACHE.'/httpd.conf']);
    }

    public function test_apache_removes_a_named_vhost(): void
    {
        (new Apache($this->host))->deleteDomainConfig('alice.test');

        $this->assertSame([['files.removeIfExists', [self::APACHE.'/vhosts/alice.test.conf']]], $this->requests());
    }

    private function appLite(): void
    {
        config([
            'env.APP_LITE_PROXY_ENABLED' => true,
            'env.APP_LITE_CA_PORT' => 8443, 'env.APP_LITE_CA_DOMAIN' => null,
            'env.APP_LITE_PROXY_CA_HOST' => 'panel-client', 'env.APP_LITE_PROXY_CA_PORT' => 9090,
            'env.APP_LITE_AA_PORT' => null, 'env.APP_LITE_AA_DOMAIN' => null,
        ]);
    }

    private function currentWebserver(string $slug): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $slug);
    }

    /** @return list<array{?string, list<string>}> the broker requests the driver made, in order */
    private function requests(): array
    {
        $requests = [];
        foreach ($this->host->journal as $entry) {
            if (preg_match('/^(?:exec|run) (\[.*\])$/', $entry, $m) === 1) {
                $argv = json_decode($m[1], true);
                if (LocalRoot::isRequest($argv)) {
                    $requests[] = [LocalRoot::action($argv), LocalRoot::values($argv)];
                }
            }
        }

        return $requests;
    }

    private function domain(string $name): Domain
    {
        $domain = $this->makeMainDomain($this->makeUser('shop'.substr(md5($name), 0, 4), [], $name), $name);
        $domain->setDetails(['document_root' => "/{$name}/public_html", 'php_version' => '8.2']);
        $domain->save();

        return $domain;
    }
}
