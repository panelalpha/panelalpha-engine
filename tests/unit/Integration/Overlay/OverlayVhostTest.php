<?php

namespace Tests\Integration\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayDirectives;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Overlays reach the site's own pages on :80 and :443, and nothing else the vhost serves. */
class OverlayVhostTest extends TestCase
{
    public function test_a_container_project_gets_the_overlay_in_its_proxied_and_static_site(): void
    {
        foreach ([true, false] as $proxied) {
            [$preamble, $http, $https, $extra] = $this->blocks($this->renderDind($proxied, false));

            $this->assertStringContainsString('geo $pa_overlay_dollar', $preamble);
            $this->assertSame(1, substr_count($this->siteLocation($http), "sub_filter '</body>'"));
            $this->assertSame(1, substr_count($this->siteLocation($https), "sub_filter '</body>'"));
            $this->assertStringNotContainsString('sub_filter', $extra);
        }
    }

    public function test_an_inline_script_overlay_sends_the_policy_with_its_hash_on_both_listeners(): void
    {
        [$preamble, $http, $https, $extra] = $this->blocks($this->renderDind(true, false, [new Overlay('<script>bar()</script>')]));

        $this->assertStringContainsString('map $upstream_http_content_security_policy $pa_overlay_csp_', $preamble);
        foreach ([$http, $https] as $block) {
            $this->assertSame(1, substr_count($this->siteLocation($block), 'proxy_hide_header Content-Security-Policy;'));
            $this->assertMatchesRegularExpression('/add_header Content-Security-Policy \$pa_overlay_csp_[0-9a-f]{16} always;/', $this->siteLocation($block));
        }
        $this->assertStringNotContainsString('Content-Security-Policy', $extra);
    }

    public function test_a_suspended_project_shows_no_overlay(): void
    {
        $this->assertStringNotContainsString('sub_filter', $this->renderDind(true, true));
    }

    public function test_without_an_overlay_the_vhost_is_unchanged(): void
    {
        $vhost = $this->renderDind(true, false, []);

        $this->assertStringNotContainsString('sub_filter', $vhost);
        $this->assertStringNotContainsString('Accept-Encoding', $vhost);
        $this->assertStringNotContainsString('geo ', $vhost);
    }

    public function test_a_classic_project_gets_the_overlay_on_both_listeners(): void
    {
        [$preamble, $http, $https] = $this->blocks($this->renderClassic());

        $this->assertStringContainsString('geo $pa_overlay_dollar', $preamble);
        $this->assertSame(1, substr_count($this->siteLocation($http), "sub_filter '</body>'"));
        $this->assertSame(1, substr_count($this->siteLocation($https), "sub_filter '</body>'"));
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
        $start = strpos($block, 'location / {');
        $end = strpos($block, 'location /shop-error-pages/');

        return substr($block, (int) $start, (int) $end - (int) $start);
    }

    /**
     * @param  ?list<Overlay>  $overlays
     */
    private function renderDind(bool $proxied, bool $suspended, ?array $overlays = null): string
    {
        $upstream = ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'];

        return $this->render('dind/virtualHost-nginx-proxy.blade.php', [
            ...$this->baseVars($suspended),
            'proxy_http' => $proxied ? $upstream : null,
            'proxy_https' => $proxied ? $upstream : null,
            'proxy_extra' => [['listen_port' => 3001, ...$upstream]],
            ...OverlayDirectives::templateVars($overlays ?? [new Overlay('<p>Price: $5</p>')]),
        ]);
    }

    private function renderClassic(): string
    {
        return $this->render('virtualHost-nginx-proxy.blade.php', [
            ...$this->baseVars(false),
            ...OverlayDirectives::templateVars([new Overlay('<p>Price: $5</p>')]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseVars(bool $suspended): array
    {
        return [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'suspended' => $suspended,
            'relative_document_root' => '/shop.example.test/public_html',
            'app_port' => 8000,
            'app_ssl_port' => 8000,
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
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
