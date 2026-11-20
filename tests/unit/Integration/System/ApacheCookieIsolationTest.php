<?php

namespace Tests\Integration\System;

use App\Lib\Domains\NamingZones;
use App\System\Services\Webserver\ApacheCookieIsolation;
use App\System\Services\Webserver\CookieScope;
use Illuminate\Support\Facades\Blade;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A project named under a shared zone neither leaks cookies to its neighbours nor accepts their requests' cookies. */
class ApacheCookieIsolationTest extends TestCase
{
    private const string NAME = 'shop-4f2a.panelalpha.online';

    private const string SIBLING = 'api-shop-4f2a.panelalpha.online';

    public function test_a_shared_name_gates_the_request_and_narrows_the_cookie_on_both_listeners(): void
    {
        $blocks = $this->blocks($this->render($this->sharedVars()));

        $this->assertCount(2, $blocks);
        foreach ($blocks as $block) {
            $this->assertStringContainsString('SetEnvIfNoCase Referer "^https?://([^/?#:]+)" pa_cookie_from=$1', $block);
            $this->assertStringContainsString('SetEnvIfNoCase Origin "^https?://([^/:]+)" pa_cookie_from=$1', $block);
            $this->assertStringContainsString('RequestHeader unset Cookie "expr=', $block);
            $this->assertStringContainsString('Header edit Set-Cookie', $block);
            $this->assertStringContainsString('Header always edit Set-Cookie', $block);
        }
        $this->assertSame(
            $this->isolationLines($blocks[0]),
            $this->isolationLines($blocks[1])
        );
    }

    public function test_the_gate_sits_in_the_vhost_so_it_also_covers_phpmyadmin(): void
    {
        $block = $this->blocks($this->render($this->sharedVars()))[0];

        $this->assertLessThan(
            (int) strpos($block, 'RewriteRule ^/phpmyadmin/(.*)$'),
            (int) strpos($block, 'RequestHeader unset Cookie')
        );
        $this->assertStringNotContainsString('RequestHeader', $this->phpHandler($block));
    }

    public function test_a_custom_domain_alone_renders_the_vhost_unchanged(): void
    {
        $vars = ApacheCookieIsolation::templateVars(
            CookieScope::of($this->zones(), ['shop.com', 'www.shop.com'], ['shop.com', 'www.shop.com'], false)
        );

        $this->assertNull($vars['cookie_isolation_request']);
        $this->assertNull($vars['cookie_isolation_response']);
        $this->assertSame($this->render([]), $this->render($vars));
    }

    public function test_only_a_neighbours_request_loses_its_cookies(): void
    {
        $pattern = $this->neighbourPattern($this->requestExpression($this->render($this->patternVars())));

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
            '1|same-site|cors|POST|'.self::SIBLING,
            '1|same-origin|cors|POST|'.self::NAME,
            '1|cross-site|cors|POST|attacker.example',
            '1|none|navigate|GET|',
            '1||||',
            '0|same-site|cors|POST|evil.panelalpha.online',
        ];
        foreach ($stripped as $request) {
            $this->assertSame(1, preg_match('#'.$pattern.'#', $request), $request);
        }
        foreach ($kept as $request) {
            $this->assertSame(0, preg_match('#'.$pattern.'#', $request), $request);
        }
    }

    public function test_the_gate_asks_about_the_request_host_case_insensitively_and_ignores_its_port(): void
    {
        $pattern = $this->hostPattern($this->requestExpression($this->render($this->patternVars())));

        foreach ([self::NAME, 'SHOP-4F2A.PanelAlpha.Online', self::NAME.':8080'] as $host) {
            $this->assertSame(1, preg_match('#'.$pattern.'#', $host), $host);
        }
        foreach (['shop.example.com', 'evil.panelalpha.online', self::NAME.'.evil.example'] as $host) {
            $this->assertSame(0, preg_match('#'.$pattern.'#', $host), $host);
        }
    }

    public function test_the_initiator_is_the_origin_else_the_referer(): void
    {
        $block = $this->blocks($this->render($this->patternVars()))[0];

        $this->assertStringContainsString('%{env:pa_cookie_from}', $this->requestExpression($block));
        $this->assertLessThan(
            (int) strpos($block, 'SetEnvIfNoCase Origin'),
            (int) strpos($block, 'SetEnvIfNoCase Referer')
        );
    }

    public function test_a_shared_parent_cookie_loses_its_domain_and_nothing_else_changes(): void
    {
        $pattern = $this->domainPattern($this->render($this->sharedVars()));

        $rewritten = [
            'a=1; path=/; domain=panelalpha.online' => 'a=1; path=/',
            'b=2; path=/; domain=.PanelAlpha.Online' => 'b=2; path=/',
            'e=5; Domain=panelalpha.online; Path=/; HttpOnly' => 'e=5; Path=/; HttpOnly',
        ];
        foreach ($rewritten as $cookie => $expected) {
            $this->assertSame($expected, preg_replace('#'.$pattern.'#', '', $cookie), $cookie);
        }
        foreach (['c=3; path=/', 'd=4; path=/; domain='.self::NAME, 'f=6; Domain=example.net; Path=/; Secure'] as $cookie) {
            $this->assertSame($cookie, preg_replace('#'.$pattern.'#', '', $cookie), $cookie);
        }
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
        return ApacheCookieIsolation::templateVars(CookieScope::of($this->zones(), [self::NAME], [self::NAME], false));
    }

    /**
     * @return array<string, mixed>
     */
    private function patternVars(): array
    {
        return ApacheCookieIsolation::templateVars(CookieScope::of($this->zones(), [self::NAME], [self::NAME, self::SIBLING], false));
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function render(array $vars): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()).'/templates/virtualHost-apache.blade.php'), $this->baseVars() + $vars);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseVars(): array
    {
        return [
            'domain' => self::NAME,
            'aliases' => [],
            'relative_document_root' => '/public_html',
            'user' => 'shop',
            'suspended' => false,
            'ips_v4' => [TestNetwork::PRIVATE_10],
            'ips_v6' => [],
            'php_port' => 9083,
            'ssl_enabled' => true,
            'ssl_cert_file' => '/home/shop/ssl-certs/'.self::NAME.'.crt',
            'ssl_cert_key_file' => '/home/shop/ssl-certs/'.self::NAME.'.key',
            'ssl_cert_ca_file' => '/home/shop/ssl-certs/'.self::NAME.'.ca',
        ];
    }

    /**
     * @return list<string> the :80 vhost, then the :443 one
     */
    private function blocks(string $vhost): array
    {
        preg_match_all('#<VirtualHost.*?</VirtualHost>#s', $vhost, $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function isolationLines(string $block): array
    {
        preg_match_all('/^.*(?:SetEnvIfNoCase|RequestHeader|Header (?:always )?edit).*$/m', $block, $matches);

        return array_map('trim', $matches[0]);
    }

    private function requestExpression(string $vhost): string
    {
        preg_match('/RequestHeader unset Cookie "expr=(.+)"$/m', $vhost, $matches);

        return $matches[1];
    }

    private function hostPattern(string $expression): string
    {
        preg_match('/\'%\{HTTP_HOST\}\' =~ m#(.+?)# &&/', $expression, $matches);

        return $matches[1];
    }

    private function neighbourPattern(string $expression): string
    {
        preg_match('/=~ m#([^#]+)#\)$/', $expression, $matches);

        return $matches[1];
    }

    private function domainPattern(string $vhost): string
    {
        preg_match('/Header always edit Set-Cookie "(.+)" ""/', $vhost, $matches);

        return $matches[1];
    }

    private function phpHandler(string $block): string
    {
        preg_match('#<FilesMatch "\\\\\.php\$">.*?</FilesMatch>#s', $block, $matches);

        return $matches[0];
    }
}
