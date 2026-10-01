<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * nginx's page-size header buffer (4k) answers 502 for any upstream whose
 * response headers are larger, while the app itself answers 200.
 */
class NginxProxyHeaderBuffersTest extends TestCase
{
    public function test_the_rendered_proxy_config_raises_the_header_buffer(): void
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

        $this->assertHeaderBufferAtLeast16k($conf);
    }

    public function test_the_shipped_initial_config_matches(): void
    {
        $this->assertHeaderBufferAtLeast16k(
            (string) file_get_contents(dirname(base_path()) . '/templates/webserver-config/nginx-proxy/nginx.conf')
        );
    }

    private function assertHeaderBufferAtLeast16k(string $conf): void
    {
        $http = substr($conf, (int) strpos($conf, 'http {'));
        $this->assertMatchesRegularExpression('/^\s*proxy_buffer_size\s+(\d+)k;/m', $http);
        preg_match('/^\s*proxy_buffer_size\s+(\d+)k;/m', $http, $m);
        $this->assertGreaterThanOrEqual(16, (int) $m[1]);
        // Raising proxy_buffer_size alone makes nginx refuse the config: the derived
        // proxy_busy_buffers_size would exceed the default 8 x 4k buffers.
        $this->assertMatchesRegularExpression('/^\s*proxy_buffers\s+\d+\s+\d+k;/m', $http);
        $this->assertMatchesRegularExpression('/^\s*proxy_busy_buffers_size\s+\d+k;/m', $http);
    }
}
