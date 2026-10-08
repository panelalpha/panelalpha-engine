<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The edge image ships ngx_brotli with its settings, and the nginx-proxy config
 * picks them up through glob includes. A config rendered before the new image
 * is built, or kept after rolling the image back, must still start, so the
 * Brotli directives live only in the image and never in the config itself.
 */
class NginxProxyBrotliTest extends TestCase
{
    private const LOAD_INCLUDE = 'include /etc/nginx/modules-enabled/*.conf;';
    private const HTTP_INCLUDE = 'include /etc/nginx/modules-http/*.conf;';

    public function test_the_rendered_config_includes_the_image_modules(): void
    {
        foreach ([false, true] as $modsecurity) {
            $conf = $this->render($modsecurity);
            $this->assertIncludesImageModules($conf);
            if ($modsecurity) {
                $this->assertLessThan(
                    strpos($conf, self::LOAD_INCLUDE),
                    strpos($conf, 'load_module modules/ngx_http_modsecurity_module.so;')
                );
            }
        }
    }

    public function test_the_shipped_initial_config_matches(): void
    {
        $this->assertIncludesImageModules($this->file('templates/webserver-config/nginx-proxy/nginx.conf'));
    }

    public function test_the_config_itself_names_no_brotli_directive(): void
    {
        // Any of these without the module loaded is an unknown directive, and nginx refuses to start.
        foreach ([$this->render(false), $this->render(true), $this->file('templates/webserver-config/nginx-proxy/nginx.conf')] as $conf) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(brotli\w*|load_module\s+\S*brotli)/m', $conf);
        }
    }

    public function test_the_image_loads_both_modules_and_compresses_what_gzip_does(): void
    {
        $load = $this->file('dockerfiles/nginx/modules-enabled/brotli.conf');
        $this->assertStringContainsString('load_module modules/ngx_http_brotli_filter_module.so;', $load);
        $this->assertStringContainsString('load_module modules/ngx_http_brotli_static_module.so;', $load);

        $http = $this->file('dockerfiles/nginx/modules-http/brotli.conf');
        $this->assertMatchesRegularExpression('/^brotli\s+on;/m', $http);
        $this->assertMatchesRegularExpression('/^brotli_comp_level\s+5;/m', $http);
        $this->assertSame(
            $this->types('gzip_types', $this->render(false)),
            $this->types('brotli_types', $http)
        );
    }

    public function test_the_image_puts_the_files_where_the_config_looks(): void
    {
        $dockerfile = $this->file('dockerfiles/Dockerfile-nginx');
        $this->assertMatchesRegularExpression('#^COPY nginx/modules-enabled/ /etc/nginx/modules-enabled/$#m', $dockerfile);
        $this->assertMatchesRegularExpression('#^COPY nginx/modules-http/ /etc/nginx/modules-http/$#m', $dockerfile);
        $this->assertMatchesRegularExpression('#^COPY --from=brotli /out/ /usr/lib/nginx/modules/$#m', $dockerfile);
        // The modules only load into the nginx they were built against.
        $this->assertSame(2, preg_match_all('/^FROM nginx:1\.31\.1\b/m', $dockerfile));
    }

    public function test_both_nginx_variants_name_the_same_image(): void
    {
        $proxy = $this->file('docker-compose.yml-nginx-proxy');
        $this->assertSame(1, preg_match('/^\s+image:\s+(\S+)/m', $proxy, $m));
        $this->assertStringContainsString("image: {$m[1]}", $this->file('docker-compose.yml-nginx'));
        $this->assertNotSame('ghcr.io/panelalpha/app-nginx:20260525', $m[1], 'the image without Brotli');
    }

    private function assertIncludesImageModules(string $conf): void
    {
        $events = strpos($conf, 'events {');
        $http = strpos($conf, 'http {');
        $this->assertNotFalse($events);
        $this->assertNotFalse($http);

        // load_module is refused once the events block has been read.
        $load = strpos($conf, self::LOAD_INCLUDE);
        $this->assertNotFalse($load);
        $this->assertLessThan($events, $load);

        $settings = strpos($conf, self::HTTP_INCLUDE);
        $this->assertNotFalse($settings);
        $this->assertGreaterThan($http, $settings);
        $this->assertLessThan(strpos($conf, 'include /etc/nginx/conf.d/*.conf;'), $settings);
    }

    /**
     * @return list<string>
     */
    private function types(string $directive, string $conf): array
    {
        $this->assertSame(1, preg_match('/^\s*' . $directive . '\s+([^;]+);/m', $conf, $m));
        $types = preg_split('/\s+/', trim($m[1])) ?: [];
        sort($types);

        return $types;
    }

    private function render(bool $modsecurity): string
    {
        return Blade::render($this->file('templates/webserver-nginx-proxy.blade.php'), [
            'modsecurity_enabled' => $modsecurity,
            'ips_v4' => [],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
        ]);
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(dirname(base_path()) . '/' . $path);
    }
}
