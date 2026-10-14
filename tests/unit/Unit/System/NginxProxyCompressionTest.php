<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The edge sent every answer uncompressed: gzip was commented out, so a CSS
 * or JS bundle crossed the wire at full size unless the app compressed it.
 */
class NginxProxyCompressionTest extends TestCase
{
    public function test_the_rendered_proxy_config_compresses_text(): void
    {
        $template = (string) file_get_contents(dirname(base_path()) . '/templates/webserver-nginx-proxy.blade.php');
        $conf = Blade::render($template, [
            'modsecurity_enabled' => false,
            'ips_v4' => [],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
        ]);

        $this->assertCompressesText($conf);
    }

    public function test_the_shipped_initial_config_matches(): void
    {
        $this->assertCompressesText(
            (string) file_get_contents(dirname(base_path()) . '/templates/webserver-config/nginx-proxy/nginx.conf')
        );
    }

    private function assertCompressesText(string $conf): void
    {
        $http = substr($conf, (int) strpos($conf, 'http {'));
        $this->assertMatchesRegularExpression('/^\s*gzip\s+on;/m', $http);
        $this->assertMatchesRegularExpression('/^\s*gzip_vary\s+on;/m', $http);
        // Every request here is proxied; without `any` one that arrives with a
        // Via header (a CDN in front) would get nothing compressed.
        $this->assertMatchesRegularExpression('/^\s*gzip_proxied\s+any;/m', $http);

        $this->assertSame(1, preg_match('/^\s*gzip_types\s+([^;]+);/m', $http, $m));
        $types = preg_split('/\s+/', trim($m[1])) ?: [];
        foreach (['text/css', 'application/javascript', 'application/json', 'image/svg+xml'] as $type) {
            $this->assertContains($type, $types);
        }
        // Compressing an event stream buffers it, and the client sees nothing until the buffer fills.
        $this->assertNotContains('text/event-stream', $types);
    }
}
