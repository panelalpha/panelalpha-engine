<?php

namespace Tests\Integration\System;

use App\Lib\Domains\NamingZones;
use App\System\Services\Webserver\CookieIsolation;
use Illuminate\Support\Facades\Blade;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A project named under a shared zone neither leaks cookies to its neighbours nor accepts their requests' cookies. */
class CookieIsolationVhostTest extends TestCase
{
    private const string NAME = 'shop-4f2a.panelalpha.online';

    public function test_a_shared_name_rewrites_parent_cookies_and_gates_every_proxied_location(): void
    {
        [$preamble, $http, $https, $extra] = $this->blocks($this->renderDind($this->sharedVars()));

        $this->assertStringNotContainsString('pa_cookie', $preamble);
        foreach ([$http, $https, $extra] as $block) {
            $this->assertStringContainsString('set $pa_cookie $http_cookie;', $block);
            $this->assertStringContainsString('proxy_cookie_domain "~(?i)^\.?(?:panelalpha\.online)$" $host;', $this->siteLocation($block));
            $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->siteLocation($block));
        }
        $this->assertStringContainsString('grpc_set_header Cookie $pa_cookie;', $https);
        $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->phpMyAdminLocation($http));
        $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->phpMyAdminLocation($https));
    }

    public function test_a_classic_project_gets_the_same_on_both_listeners(): void
    {
        [, $http, $https] = $this->blocks($this->render('virtualHost-nginx-proxy.blade.php', [...$this->baseVars(), ...$this->sharedVars()]));

        foreach ([$http, $https] as $block) {
            $this->assertStringContainsString('set $pa_cookie $http_cookie;', $block);
            $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->siteLocation($block));
            $this->assertStringContainsString('proxy_cookie_domain', $this->siteLocation($block));
            $this->assertStringContainsString('proxy_set_header Cookie $pa_cookie;', $this->phpMyAdminLocation($block));
        }
    }

    public function test_a_custom_domain_alone_renders_the_vhost_unchanged(): void
    {
        $vars = CookieIsolation::templateVars($this->zones(), ['shop.com', 'www.shop.com'], ['shop.com', 'www.shop.com'], false);

        $this->assertSame($this->renderDind([]), $this->renderDind($vars));
    }

    public function test_a_preview_through_the_front_is_gated_on_the_peer_address(): void
    {
        $vars = CookieIsolation::templateVars($this->zones(), ['shop.com'], ['shop.com', 'shop-4f2a.panelalpha.online'], true);

        $this->assertStringContainsString('set $pa_cookie_peer "$realip_remote_addr|$remote_addr";', (string) $vars['cookie_isolation_server']);
        $this->assertStringNotContainsString('$host ~*', (string) $vars['cookie_isolation_server']);
        $this->assertSame('proxy_set_header Cookie $pa_cookie;', $vars['cookie_isolation_location']);
    }

    public function test_the_front_check_tells_a_forwarded_request_from_a_direct_one(): void
    {
        $pattern = '/^(.*)\|\1$/';

        $this->assertSame(1, preg_match($pattern, TestNetwork::DOCUMENTATION_3.'|'.TestNetwork::DOCUMENTATION_3));
        $this->assertSame(0, preg_match($pattern, TestNetwork::DOCUMENTATION_2.'|'.TestNetwork::DOCUMENTATION_3));
    }

    public function test_only_a_neighbours_request_loses_its_cookies(): void
    {
        $pattern = $this->nginxPattern(CookieIsolation::neighbourRequestPattern([self::NAME, 'api-shop-4f2a.panelalpha.online']));

        $stripped = [
            '1|same-site|cors|POST|evil.panelalpha.online',
            '1|same-site|navigate|POST|evil.panelalpha.online',
            '1|same-site|no-cors|GET|evil.go.panelalpha.online',
            '1|same-site|websocket|GET|evil.panelalpha.online',
            '1|same-site|cors|POST|',
            '1|same-site|cors|POST|evil-shop-4f2a.panelalpha.online',
        ];
        $kept = [
            '1|same-site|navigate|GET|evil.panelalpha.online',
            '1|same-site|navigate|HEAD|evil.panelalpha.online',
            '1|same-site|cors|POST|api-shop-4f2a.panelalpha.online',
            '1|same-origin|cors|POST|'.self::NAME,
            '1|cross-site|cors|POST|attacker.example',
            '1|none|navigate|GET|',
            '1||||',
            '0|same-site|cors|POST|evil.panelalpha.online',
        ];
        foreach ($stripped as $request) {
            $this->assertSame(1, preg_match($pattern, $request), $request);
        }
        foreach ($kept as $request) {
            $this->assertSame(0, preg_match($pattern, $request), $request);
        }
    }

    public function test_the_domain_rewrite_matches_a_shared_parent_with_or_without_its_dot(): void
    {
        $vars = CookieIsolation::templateVars($this->zones(), ['a.go.panelalpha.online'], [], false);
        preg_match('/proxy_cookie_domain "~(.+)" \$host;/', (string) $vars['cookie_isolation_location'], $m);
        $pattern = $this->nginxPattern($m[1]);

        foreach (['panelalpha.online', '.panelalpha.online', 'go.panelalpha.online', 'GO.PANELALPHA.ONLINE'] as $domain) {
            $this->assertSame(1, preg_match($pattern, $domain), $domain);
        }
        foreach (['a.go.panelalpha.online', 'online', 'other.example'] as $domain) {
            $this->assertSame(0, preg_match($pattern, $domain), $domain);
        }
    }

    public function test_the_initiator_is_the_origin_host_else_the_referer_host(): void
    {
        $vars = CookieIsolation::templateVars($this->zones(), [self::NAME], [], false);
        preg_match_all('/if \(\$http_(referer|origin) ~\* "([^"]+)"\)/', (string) $vars['cookie_isolation_server'], $m, PREG_SET_ORDER);
        [$referer, $origin] = [$this->nginxPattern($m[0][2]), $this->nginxPattern($m[1][2])];

        preg_match($referer, 'https://evil.panelalpha.online/page?x=1', $r);
        preg_match($origin, 'https://evil.panelalpha.online:8443', $o);

        $this->assertSame('evil.panelalpha.online', $r[1]);
        $this->assertSame('evil.panelalpha.online', $o[1]);
        $this->assertSame(0, preg_match($origin, 'null'));
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
        return CookieIsolation::templateVars($this->zones(), [self::NAME], [self::NAME], false);
    }

    private function nginxPattern(string $regex): string
    {
        return '/'.str_replace('/', '\/', $regex).'/i';
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function renderDind(array $vars): string
    {
        $upstream = ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'];

        return $this->render('dind/virtualHost-nginx-proxy.blade.php', [
            ...$this->baseVars(),
            'proxy_http' => $upstream,
            'proxy_https' => $upstream,
            'proxy_extra' => [['listen_port' => 3001, ...$upstream]],
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
            'ips_v4' => [],
            'ips_v6' => [],
            'suspended' => false,
            'relative_document_root' => '/'.self::NAME.'/public_html',
            'app_port' => 8000,
            'app_ssl_port' => 8000,
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
        $start = (int) strpos($block, 'location / {');
        $end = strpos($block, 'location /shop-error-pages/', $start);

        return substr($block, $start, ($end === false ? strlen($block) : $end) - $start);
    }

    private function phpMyAdminLocation(string $block): string
    {
        $start = (int) strpos($block, 'location /phpmyadmin {');

        return substr($block, $start, (int) strpos($block, 'location /panelalpha-sso', $start) - $start);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function render(string $template, array $vars): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()).'/templates/'.$template), $vars);
    }
}
