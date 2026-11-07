<?php

namespace Tests\Unit\System;

use App\Lib\Domains\NamingZones;
use App\System\Services\Webserver\CookieScope;
use App\System\Services\Webserver\LiteSpeedCookieIsolation;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** A project named under a shared zone marks its neighbours' requests on both LiteSpeed servers. */
class LiteSpeedCookieIsolationTest extends TestCase
{
    private const string NAME = 'shop-4f2a.panelalpha.online';

    public function test_litespeed_clears_the_cookie_header_of_a_neighbours_request(): void
    {
        $vhost = $this->render('virtualHostConfig-litespeed.blade.php', [...$this->enterpriseVars(), ...$this->sharedVars(true)]);

        $this->assertStringContainsString('<extraHeaders>RequestHeader unset Cookie env=pa_cookie_strip</extraHeaders>', $vhost);
        $this->assertLessThan(strpos($vhost, 'RewriteRule .* - [E=pmapass:1]'), strpos($vhost, 'RewriteRule .* - [E=pa_cookie_strip:1]'));
    }

    public function test_openlitespeed_marks_the_request_without_a_header_operation(): void
    {
        $vhost = $this->render('virtualHostConfig-openlitespeed.blade.php', [...$this->openlitespeedVars(), ...$this->sharedVars(false)]);

        $this->assertStringContainsString('  RewriteRule .* - [E=pa_cookie_strip:1]', $vhost);
        $this->assertStringNotContainsString('RequestHeader', $vhost);
        $this->assertStringNotContainsString('extraHeaders', $vhost);
    }

    public function test_the_phpmyadmin_proxy_refuses_a_marked_request_on_both_servers(): void
    {
        foreach ([['virtualHostConfig-litespeed.blade.php', 'enterpriseVars'], ['virtualHostConfig-openlitespeed.blade.php', 'openlitespeedVars']] as [$template, $vars]) {
            $vhost = $this->render($template, [...$this->{$vars}(), ...$this->sharedVars($template === 'virtualHostConfig-litespeed.blade.php')]);

            $gate = (int) strpos($vhost, 'RewriteCond %{ENV:pa_cookie_strip} !^1$');
            $this->assertGreaterThan((int) strpos($vhost, 'RewriteCond %{ENV:pmapass} =1'), $gate);
            $this->assertLessThan((int) strpos($vhost, 'RewriteRule ^/phpmyadmin/(.*)$'), $gate);
        }
    }

    public function test_a_custom_domain_alone_renders_both_vhosts_unchanged(): void
    {
        $vars = LiteSpeedCookieIsolation::templateVars($this->customDomainScope(), true);

        $this->assertSame(
            $this->render('virtualHostConfig-litespeed.blade.php', $this->enterpriseVars()),
            $this->render('virtualHostConfig-litespeed.blade.php', [...$this->enterpriseVars(), ...$vars]),
        );
        $this->assertSame(
            $this->render('virtualHostConfig-openlitespeed.blade.php', $this->openlitespeedVars()),
            $this->render('virtualHostConfig-openlitespeed.blade.php', [...$this->openlitespeedVars(), ...$vars]),
        );
    }

    public function test_only_a_neighbours_request_is_marked(): void
    {
        $rules = (string) LiteSpeedCookieIsolation::templateVars($this->sharedScope(), true)['cookie_isolation_rewrite'];
        $host = $this->pattern($rules, '%{HTTP_HOST}');
        $referer = $this->pattern($rules, '%{HTTP:Referer}');
        $origin = $this->pattern($rules, '%{HTTP:Origin}', 1);

        $this->assertSame(1, preg_match($host, self::NAME));
        $this->assertSame(1, preg_match($host, self::NAME.':8443'));
        $this->assertSame(0, preg_match($host, 'shop.com'));
        $this->assertSame(0, preg_match($host, 'evil.panelalpha.online'));

        foreach (['https://shop.example.com/x', 'https://shop.example.com', 'HTTPS://SHOP.EXAMPLE.COM:8443/x'] as $own) {
            $this->assertSame(1, preg_match($referer, $own), $own);
        }
        foreach (['https://evil.panelalpha.online/x', 'shop.example.com'] as $neighbour) {
            $this->assertSame(0, preg_match($referer, $neighbour), $neighbour);
        }

        $this->assertSame(1, preg_match($origin, 'https://shop.example.com'));
        $this->assertSame(1, preg_match($origin, 'https://shop-4f2a.panelalpha.online:8443/'));
        $this->assertSame(0, preg_match($origin, 'https://evil.panelalpha.online'));
        $this->assertSame(0, preg_match($origin, 'null'));
    }

    public function test_the_origin_replaces_the_referer_only_when_it_carries_a_host(): void
    {
        $rules = (string) LiteSpeedCookieIsolation::templateVars($this->sharedScope(), true)['cookie_isolation_rewrite'];

        preg_match_all('~RewriteCond %\{ENV:(pa_cookie_[a-z_]+)\}~', $rules, $matches);
        $this->assertSame(['pa_cookie_top', 'pa_cookie_from_own'], $matches[1]);
        $this->assertStringContainsString('RewriteCond %{HTTP:Origin} ^https?://[^/:]', $rules);
        $this->assertLessThan(strpos($rules, 'RewriteCond %{HTTP:Origin} ^https?://(?:'), strpos($rules, 'RewriteCond %{HTTP:Origin} ^https?://[^/:]'));
    }

    private function sharedVars(bool $clearsRequestCookie): array
    {
        return LiteSpeedCookieIsolation::templateVars($this->sharedScope(), $clearsRequestCookie);
    }

    private function sharedScope(): CookieScope
    {
        return CookieScope::of($this->zones(), [self::NAME], [self::NAME, 'shop.example.com'], false);
    }

    private function customDomainScope(): CookieScope
    {
        return CookieScope::of($this->zones(), ['shop.com', 'www.shop.com'], ['shop.com', 'www.shop.com'], false);
    }

    private function zones(): NamingZones
    {
        return NamingZones::of(['panelalpha.online', 'panelalpha.direct', 'sites.example.com']);
    }

    private function pattern(string $rules, string $variable, int $occurrence = 0): string
    {
        preg_match_all('~RewriteCond '.preg_quote($variable, '~').' (\S+)~', $rules, $matches);

        return '~'.$matches[1][$occurrence].'~i';
    }

    /** @return array<string, mixed> */
    private function enterpriseVars(): array
    {
        return [
            'user' => 'shop',
            'domain' => self::NAME,
            'relative_document_root' => '/public_html',
            'suspended' => false,
            'php_port' => '9000',
            'lsphp_max_conns' => 35,
            'cache_engine' => '7',
            'cache_store_path' => '/opt/engine/data/lscache',
            'cache_check_public' => '1',
            'cache_check_private' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function openlitespeedVars(): array
    {
        return [
            'user' => 'shop',
            'domain' => self::NAME,
            'aliases' => [],
            'relative_document_root' => '/public_html',
            'suspended' => false,
            'php_port' => '9000',
            'lsphp_max_conns' => 35,
            'cache_enabled' => '1',
            'cache_store_path' => '/opt/engine/data/lscache',
        ];
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function render(string $template, array $vars): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()).'/templates/'.$template), $vars);
    }
}
