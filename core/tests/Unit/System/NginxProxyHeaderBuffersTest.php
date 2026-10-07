<?php

namespace Tests\Unit\System;

use App\System\Services\Webserver\NginxProxy;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * nginx answers 502 for any upstream whose response headers do not fit its
 * header buffer, while the app itself answers 200. The edge takes a 64 KB one.
 */
class NginxProxyHeaderBuffersTest extends TestCase
{
    public function test_the_rendered_proxy_config_takes_a_64k_header(): void
    {
        $conf = $this->render('webserver-nginx-proxy.blade.php');
        $this->assertHeaderRoom($conf, 'proxy');
        $this->assertHeaderBlockFits($conf, 'grpc');
    }

    public function test_the_shipped_initial_config_matches(): void
    {
        $conf = $this->template('webserver-config/nginx-proxy/nginx.conf');
        $this->assertHeaderRoom($conf, 'proxy');
        $this->assertHeaderBlockFits($conf, 'grpc');
    }

    public function test_the_nginx_webserver_takes_a_64k_header_from_a_proxy_or_fastcgi(): void
    {
        foreach ([$this->render('webserver-nginx.blade.php'), $this->template('webserver-config/nginx/nginx.conf')] as $conf) {
            $this->assertHeaderRoom($conf, 'proxy');
            $this->assertHeaderRoom($conf, 'fastcgi');
        }
    }

    // Vhosts and proxy rules inherit the http-level room; their own value would replace it.
    public function test_no_vhost_or_proxy_rule_sets_its_own_header_buffers(): void
    {
        $sources = [
            'dind vhost' => $this->template('dind/virtualHost-nginx-proxy.blade.php'),
            'php hosting vhost' => $this->template('virtualHost-nginx-proxy.blade.php'),
            'app-lite vhost' => $this->template('virtualHost-nginx-proxy-app-lite.blade.php'),
            'nginx vhost' => $this->template('virtualHost-nginx.blade.php'),
            'nginx app-lite vhost' => $this->template('virtualHost-nginx-app-lite.blade.php'),
            'proxy rule' => NginxProxy::renderHttpProxyRule([
                'id' => 3,
                'listen_ip' => '*',
                'listen_port' => 8081,
                'server_names' => ['_'],
                'upstream_protocol' => 'http',
                'upstream_host' => 'shop',
                'upstream_port' => 3000,
                'ssl_enabled' => false,
            ]),
        ];

        foreach ($sources as $name => $source) {
            $this->assertDoesNotMatchRegularExpression('/\b(proxy|fastcgi|grpc)_(buffer_size|buffers|busy_buffers_size)\b/', $source, $name);
        }
    }

    private function assertHeaderRoom(string $conf, string $kind): void
    {
        $size = $this->assertHeaderBlockFits($conf, $kind);
        $http = substr($conf, (int) strpos($conf, 'http {'));
        $this->assertSame(1, preg_match("/^\s*{$kind}_buffers\s+(\d+)\s+(\d+)k;/m", $http, $buffers), "{$kind}_buffers");
        $this->assertSame(1, preg_match("/^\s*{$kind}_busy_buffers_size\s+(\d+)k;/m", $http, $busy), "{$kind}_busy_buffers_size");
        [$count, $one, $busy] = [(int) $buffers[1], (int) $buffers[2], (int) $busy[1]];

        // Otherwise nginx refuses the config and sites-http does not start.
        $this->assertGreaterThanOrEqual(max($size, $one), $busy, "{$kind}_busy_buffers_size below the buffer size");
        $this->assertLessThanOrEqual(($count - 1) * $one, $busy, "{$kind}_busy_buffers_size above all {$kind}_buffers but one");
    }

    // The whole header block has to fit in the one buffer; returns its size in k.
    private function assertHeaderBlockFits(string $conf, string $kind): int
    {
        $http = substr($conf, (int) strpos($conf, 'http {'));
        $this->assertSame(1, preg_match("/^\s*{$kind}_buffer_size\s+(\d+)k;/m", $http, $size), "{$kind}_buffer_size");
        $this->assertGreaterThanOrEqual(strlen($this->headerBlock()), (int) $size[1] * 1024, "{$kind}_buffer_size");

        return (int) $size[1];
    }

    // 64 KB of values in 64 headers, as a PHP app behind the edge sent them: 66,442 bytes in all.
    private function headerBlock(): string
    {
        $block = "HTTP/1.1 200 OK\r\nDate: Mon, 05 Oct 2026 15:10:40 GMT\r\nServer: Apache/2.4.68 (Debian)\r\n"
            . "Content-Length: 29\r\nContent-Type: text/plain;charset=UTF-8\r\n";
        for ($i = 0; $i < 64; $i++) {
            $block .= "X-Big-{$i}: " . str_repeat('a', 1024) . "\r\n";
        }

        return $block . "\r\n";
    }

    private function render(string $name): string
    {
        return Blade::render($this->template($name), [
            'modsecurity_enabled' => false,
            'ips_v4' => [],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
        ]);
    }

    private function template(string $path): string
    {
        return (string) file_get_contents(dirname(base_path()) . '/templates/' . $path);
    }
}
