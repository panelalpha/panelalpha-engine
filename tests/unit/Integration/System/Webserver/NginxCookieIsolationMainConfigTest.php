<?php

namespace Tests\Integration\System\Webserver;

use App\Models\Domain;
use App\Models\Setting;
use App\System\Services\Webserver\Nginx;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A vhost that needs njs never reaches disk before the main config it depends on loads it. */
class NginxCookieIsolationMainConfigTest extends TestCase
{
    use InMemoryDatabase;

    private const string MAIN_CONFIG = '/opt/engine/webserver-config/nginx/nginx.conf';

    private const string MAIN_TEMPLATE = '/opt/engine/templates/webserver-nginx.blade.php';

    private const string SCRIPT = '/opt/engine/webserver-config/nginx/njs/cookie-isolation.js';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2, 'default_ipv6' => '2001:db8::7']);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
        $this->host->files[self::MAIN_TEMPLATE] = (string) file_get_contents(
            dirname(base_path()).'/templates/webserver-nginx.blade.php'
        );
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_gated_vhost_renders_the_main_config_that_never_loaded_njs(): void
    {
        $stale = "worker_processes  auto;\n\nhttp {\n    include /etc/nginx/conf.d/*.conf;\n}\n";
        $this->host->files[self::MAIN_CONFIG] = $stale;

        (new Nginx($this->host))->createDomainConfig($this->domain('shop-4f2a.panelalpha.online'));

        $main = $this->host->files[self::MAIN_CONFIG];
        $this->assertStringContainsString('load_module modules/ngx_http_js_module.so;', $main);
        $this->assertStringContainsString('js_import pa_cookie_isolation from /opt/panelalpha/shared-hosting', $main);
        $this->assertArrayHasKey(self::SCRIPT, $this->host->files);
        $this->assertLessThan(
            (int) strpos($this->host->snapshot(), 'put '.self::MAIN_CONFIG),
            (int) strpos($this->host->snapshot(), 'put '.self::SCRIPT),
        );
        $this->assertStringContainsString(
            'js_header_filter pa_cookie_isolation.hostScopedCookies;',
            $this->host->files[$this->vhost('shop-4f2a.panelalpha.online')]
        );
    }

    public function test_a_gated_vhost_leaves_a_main_config_that_already_imports_the_script_alone(): void
    {
        $rendered = "load_module modules/ngx_http_js_module.so;\njs_import pa_cookie_isolation from /opt/panelalpha/x.js;\n";
        $this->host->files[self::MAIN_CONFIG] = $rendered;

        (new Nginx($this->host))->createDomainConfig($this->domain('shop-4f2a.panelalpha.online'));

        $this->assertSame($rendered, $this->host->files[self::MAIN_CONFIG]);
        $this->assertStringNotContainsString('put '.self::MAIN_CONFIG, $this->host->snapshot());
    }

    public function test_a_custom_domain_vhost_leaves_the_main_config_alone(): void
    {
        $stale = "worker_processes  auto;\n\nhttp {\n    include /etc/nginx/conf.d/*.conf;\n}\n";
        $this->host->files[self::MAIN_CONFIG] = $stale;

        (new Nginx($this->host))->createDomainConfig($this->domain('shop.com', ['www.shop.com']));

        $this->assertSame($stale, $this->host->files[self::MAIN_CONFIG]);
        $this->assertStringNotContainsString('put '.self::MAIN_CONFIG, $this->host->snapshot());
        $this->assertStringNotContainsString('pa_cookie', $this->host->files[$this->vhost('shop.com')]);
    }

    /** @param list<string> $aliases */
    private function domain(string $name, array $aliases = []): Domain
    {
        $user = $this->makeUser('shop', [], $name);
        $domain = $this->makeMainDomain($user, $name);
        $domain->setDetails(['document_root' => "/{$name}/public_html", 'aliases' => $aliases]);
        $domain->save();

        return $domain;
    }

    private function vhost(string $name): string
    {
        return '/opt/engine/webserver-config/nginx/vhosts/'.$name.'.conf';
    }
}
