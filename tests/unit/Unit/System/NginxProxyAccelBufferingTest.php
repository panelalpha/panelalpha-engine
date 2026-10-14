<?php

namespace Tests\Unit\System;

use App\System\Services\Webserver\NginxProxy;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * nginx hides X-Accel-Buffering by default, so a front before the edge (the
 * panelalpha.online tunnel) never learns that the app asked not to buffer.
 * Every location that relays an app's answer passes it on.
 */
class NginxProxyAccelBufferingTest extends TestCase
{
    public function test_every_proxied_dind_location_passes_the_header_on(): void
    {
        foreach (['http', 'https'] as $protocol) {
            $blocks = $this->blocks($this->renderDind($protocol));
            $this->assertCount(3, $blocks, 'the :80, :443 and extra listener blocks');

            foreach ($blocks as $i => $block) {
                $this->assertSame(1, substr_count($this->rootLocation($block), 'proxy_pass_header X-Accel-Buffering;'), "{$protocol} block {$i}");
            }
        }
    }

    public function test_a_proxy_rule_passes_the_header_on(): void
    {
        $rule = NginxProxy::renderHttpProxyRule([
            'id' => 3,
            'listen_ip' => '*',
            'listen_port' => 8081,
            'server_names' => ['_'],
            'upstream_protocol' => 'http',
            'upstream_host' => 'shop',
            'upstream_port' => 3000,
            'ssl_enabled' => false,
        ]);

        $this->assertSame(1, substr_count($this->rootLocation($rule), 'proxy_pass_header X-Accel-Buffering;'));
    }

    // nginx keeps honouring the header for its own buffering; buffering itself stays on.
    public function test_buffering_is_left_as_it_was(): void
    {
        $sources = [
            'dind vhost' => $this->renderDind('http'),
            'proxy rule' => NginxProxy::renderHttpProxyRule([
                'id' => 3, 'listen_ip' => '*', 'listen_port' => 8081, 'server_names' => ['_'],
                'upstream_protocol' => 'http', 'upstream_host' => 'shop', 'upstream_port' => 3000, 'ssl_enabled' => false,
            ]),
        ];

        foreach ($sources as $name => $source) {
            $this->assertStringNotContainsString('proxy_buffering', $source, $name);
            $this->assertStringNotContainsString('proxy_ignore_headers', $source, $name);
        }
    }

    /**
     * @return list<string>
     */
    private function blocks(string $vhost): array
    {
        $parts = preg_split('/^\s*server \{/m', $vhost) ?: [];
        array_shift($parts);

        return array_values($parts);
    }

    private function rootLocation(string $block): string
    {
        $start = strpos($block, 'location / {');
        $this->assertNotFalse($start, 'location /');
        $end = strpos($block, "\n    location ", $start + 1);
        $end = $end === false ? strpos($block, "\n        location ", $start + 1) : $end;

        return substr($block, $start, $end === false ? null : $end - $start);
    }

    private function renderDind(string $httpsProtocol): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()) . '/templates/dind/virtualHost-nginx-proxy.blade.php'), [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'relative_document_root' => '/shop.example.test/public_html',
            'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_https' => ['host' => 'shop', 'port' => 8000, 'protocol' => $httpsProtocol],
            'proxy_extra' => [['listen_port' => 3001, 'host' => 'shop', 'port' => 3000, 'protocol' => 'http']],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
        ]);
    }
}
