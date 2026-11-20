<?php

namespace Tests\Integration\System;

use App\Lib\Domains\NamingZones;
use App\System\Services\Webserver\CookieIsolation;
use App\System\Services\Webserver\CookieScope;
use App\System\Services\Webserver\NginxCookieIsolation;
use Illuminate\Support\Facades\Blade;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A project named under a shared zone neither leaks cookies to its neighbours nor accepts their requests' cookies on the classic nginx server. */
class NginxCookieIsolationTest extends TestCase
{
    private const string NAME = 'shop-4f2a.panelalpha.online';

    public function test_a_shared_name_gates_both_listeners_and_narrows_parent_cookies(): void
    {
        [$preamble, $http, $https] = $this->blocks($this->render($this->sharedVars()));

        $this->assertStringNotContainsString('pa_cookie', $preamble);
        foreach ([$http, $https] as $block) {
            $this->assertStringContainsString('js_var $pa_cookie_shared_parents "panelalpha.online";', $block);
            $this->assertStringContainsString('set $pa_cookie $http_cookie;', $block);
            $this->assertStringContainsString(CookieIsolation::neighbourRequestPattern([self::NAME]), $block);
            $this->assertStringContainsString('fastcgi_param  HTTP_COOKIE $pa_cookie;', $this->phpLocation($block));
            $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->phpMyAdminLocation($block));
            $this->assertStringContainsString('proxy_cookie_domain "~(?i)^\.?(?:panelalpha\.online)$" $host;', $this->phpMyAdminLocation($block));
            foreach ([$this->siteLocation($block), $this->phpLocation($block)] as $location) {
                $this->assertStringContainsString('js_header_filter pa_cookie_isolation.hostScopedCookies;', $location);
            }
        }
    }

    public function test_every_answer_the_app_produces_passes_the_response_filter(): void
    {
        $vhost = $this->render($this->sharedVars());

        $this->assertSame(4, substr_count($vhost, 'js_header_filter pa_cookie_isolation.hostScopedCookies;'));
        foreach ($this->blocks($vhost) as $index => $block) {
            if ($index === 0) {
                continue;
            }
            $this->assertStringNotContainsString('js_header_filter', $this->phpMyAdminLocation($block));
        }
    }

    public function test_the_fastcgi_cookie_is_set_after_the_params_it_overrides_come_in(): void
    {
        foreach ($this->blocks($this->render($this->sharedVars())) as $index => $block) {
            if ($index === 0) {
                continue;
            }
            $location = $this->phpLocation($block);
            $this->assertLessThan(
                (int) strpos($location, 'fastcgi_param  HTTP_COOKIE $pa_cookie;'),
                (int) strpos($location, 'include fastcgi_params;')
            );
        }
    }

    public function test_a_custom_domain_alone_renders_the_vhost_unchanged(): void
    {
        $vars = CookieIsolation::templateVars($this->zones(), ['shop.com', 'www.shop.com'], ['shop.com', 'www.shop.com'], false);

        $this->assertSame(
            $this->render([]),
            $this->render([...$vars, ...NginxCookieIsolation::templateVars(CookieScope::of($this->zones(), ['shop.com', 'www.shop.com'], ['shop.com', 'www.shop.com'], false))])
        );
    }

    public function test_the_filter_is_handed_every_shared_parent_of_the_vhost(): void
    {
        $scope = CookieScope::of($this->zones(), ['a.panelalpha.online', 'www.sites.example.com'], [], false);

        $this->assertSame(
            ['cookie_isolation_shared_parents' => 'panelalpha.online sites.example.com example.com'],
            NginxCookieIsolation::templateVars($scope)
        );
        $this->assertSame(
            ['cookie_isolation_shared_parents' => null],
            NginxCookieIsolation::templateVars(CookieScope::of($this->zones(), ['shop.com'], ['shop.com'], false))
        );
    }

    public function test_a_name_above_a_zone_narrows_parent_cookies_without_gating_its_requests(): void
    {
        $zones = NamingZones::of(['apps.shop.com']);
        $scope = CookieScope::of($zones, ['www.shop.com'], ['www.shop.com'], false);
        [, $http, $https] = $this->blocks($this->render([...CookieIsolation::directives($scope), ...NginxCookieIsolation::templateVars($scope)]));

        foreach ([$http, $https] as $block) {
            $this->assertStringContainsString('js_var $pa_cookie_shared_parents "shop.com";', $block);
            $this->assertStringContainsString('js_header_filter pa_cookie_isolation.hostScopedCookies;', $this->phpLocation($block));
            $this->assertStringNotContainsString('set $pa_cookie ', $block);
            $this->assertStringNotContainsString('HTTP_COOKIE', $this->phpLocation($block));
        }
    }

    public function test_the_main_config_loads_the_module_and_imports_the_shipped_script(): void
    {
        $main = Blade::render((string) file_get_contents(dirname(base_path()).'/templates/webserver-nginx.blade.php'), [
            'modsecurity_enabled' => false,
            'ips_v4' => [TestNetwork::public(2)],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
        ]);

        $this->assertStringContainsString('load_module modules/ngx_http_js_module.so;', $main);
        $this->assertStringContainsString('js_import pa_cookie_isolation from /opt/panelalpha/shared-hosting/webserver-config/nginx/njs/cookie-isolation.js;', $main);
        $this->assertFileExists(dirname(base_path()).'/templates/webserver-config/nginx/njs/cookie-isolation.js');
    }

    private function zones(): NamingZones
    {
        return NamingZones::of(['panelalpha.online', 'panelalpha.direct', 'sites.example.com']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedVars(): array
    {
        $names = [self::NAME];

        return [
            ...CookieIsolation::templateVars($this->zones(), $names, $names, false),
            ...NginxCookieIsolation::templateVars(CookieScope::of($this->zones(), $names, $names, false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function render(array $vars): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()).'/templates/virtualHost-nginx.blade.php'), [
            ...$this->baseVars(),
            ...$vars,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseVars(): array
    {
        return [
            'user' => 'shop',
            'domain' => self::NAME,
            'aliases' => [],
            'ips_v4' => [TestNetwork::public(2)],
            'ips_v6' => [],
            'suspended' => false,
            'relative_document_root' => '/'.self::NAME.'/public_html',
            'php_port' => 9000,
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
        ];
    }

    /**
     * @return list<string> what precedes the first server, then each server block
     */
    private function blocks(string $vhost): array
    {
        return array_values(preg_split('/^\s*server \{/m', $vhost) ?: []);
    }

    private function siteLocation(string $block): string
    {
        return $this->between($block, 'location / {', 'location ~ \.php$ {');
    }

    private function phpLocation(string $block): string
    {
        return $this->between($block, 'location ~ \.php$ {', null);
    }

    private function phpMyAdminLocation(string $block): string
    {
        return $this->between($block, 'location ^~ /phpmyadmin {', 'location /shop-error-pages/');
    }

    private function between(string $block, string $from, ?string $to): string
    {
        $start = (int) strpos($block, $from);
        $end = $to === null ? false : strpos($block, $to, $start);

        return substr($block, $start, ($end === false ? strlen($block) : $end) - $start);
    }
}
