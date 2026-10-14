<?php

namespace Tests\Unit\System;

use App\System\Services\Webserver\NginxProxy;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * stream.conf has to be something nginx loads: once it is included, a line
 * nginx refuses takes every site on the host down with it.
 */
class NginxProxyStreamRulesTest extends TestCase
{
    public function test_a_tcp_rule_listens_without_a_transport_keyword(): void
    {
        $conf = NginxProxy::streamConfig([$this->rule(7, 'tcp', '*', 25212, 'tr12i', 3000)]);

        $this->assertMatchesRegularExpression('/^\s*listen 25212;$/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/listen[^;]*TCP/i', $conf);
        $this->assertMatchesRegularExpression('/^\s*proxy_pass stream_proxy_7;$/m', $conf);
    }

    public function test_a_udp_rule_says_udp(): void
    {
        $conf = NginxProxy::streamConfig([$this->rule(8, 'udp', '10.0.0.5', 5353, 'tr12i', 53)]);

        $this->assertMatchesRegularExpression('/^\s*listen 10\.0\.0\.5:5353 udp;$/m', $conf);
    }

    public function test_an_ipv6_listen_address_is_bracketed(): void
    {
        $conf = NginxProxy::streamConfig([$this->rule(9, 'tcp', '2a01:4f8::1', 25212, 'tr12i', 3000)]);

        $this->assertMatchesRegularExpression('/^\s*listen \[2a01:4f8::1\]:25212;$/m', $conf);
    }

    public function test_a_wildcard_listens_on_ipv6_too_when_the_host_has_it(): void
    {
        $tcp = NginxProxy::streamConfig([$this->rule(7, 'tcp', '*', 25212, 'tr12i', 3000)], true);
        $this->assertMatchesRegularExpression('/^\s*listen 25212;\n\s*listen \[::\]:25212;$/m', $tcp);

        $udp = NginxProxy::streamConfig([$this->rule(8, 'udp', '*', 5353, 'tr12i', 53)], true);
        $this->assertMatchesRegularExpression('/^\s*listen 5353 udp;\n\s*listen \[::\]:5353 udp;$/m', $udp);

        // An address is still that address alone, and an IPv4-only host never gets [::].
        $this->assertStringNotContainsString('[::]', NginxProxy::streamConfig([$this->rule(9, 'tcp', '10.0.0.5', 25212, 'tr12i', 3000)], true));
        $this->assertStringNotContainsString('[::]', NginxProxy::streamConfig([$this->rule(9, 'tcp', '2a01:4f8::1', 25212, 'tr12i', 3000)], true));
        $this->assertStringNotContainsString('[::]', NginxProxy::streamConfig([$this->rule(7, 'tcp', '*', 25212, 'tr12i', 3000)]));
    }

    public function test_the_upstream_is_resolved_at_run_time(): void
    {
        $conf = NginxProxy::streamConfig([$this->rule(7, 'tcp', '*', 25212, 'tr12i', 3000)]);

        $upstream = substr($conf, (int) strpos($conf, 'upstream stream_proxy_7 {'));
        $upstream = substr($upstream, 0, (int) strpos($upstream, '}'));
        $this->assertMatchesRegularExpression('/^\s*server tr12i:3000 resolve;$/m', $upstream);
        $this->assertMatchesRegularExpression('/^\s*resolver 127\.0\.0\.54\b/m', $upstream);
        // `resolve` needs a shared zone; the http rules' `upstreams` zone belongs to another module.
        $this->assertMatchesRegularExpression('/^\s*zone stream_upstreams \d+k;$/m', $upstream);
    }

    public function test_generated_rules_and_no_rules_render_nothing(): void
    {
        $rule = $this->rule(7, 'tcp', '*', 25212, 'tr12i', 3000);
        $rule['is_generated'] = true;

        $this->assertStringNotContainsString('server', NginxProxy::streamConfig([$rule]));
        $this->assertStringNotContainsString('server', NginxProxy::streamConfig([]));
    }

    public function test_the_main_config_loads_the_stream_rules(): void
    {
        $template = (string) file_get_contents(dirname(base_path()) . '/templates/webserver-nginx-proxy.blade.php');
        $rendered = Blade::render($template, [
            'modsecurity_enabled' => false,
            'ips_v4' => [],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
        ]);

        $this->assertLoadsStreamRules($rendered);
        $this->assertLoadsStreamRules(
            (string) file_get_contents(dirname(base_path()) . '/templates/webserver-config/nginx-proxy/nginx.conf')
        );
    }

    private function assertLoadsStreamRules(string $conf): void
    {
        // Top level, beside http {}: stream directives are refused inside it.
        $this->assertMatchesRegularExpression(
            '/^stream \{\s*include \/opt\/panelalpha\/shared-hosting\/webserver-config\/nginx-proxy\/stream\[\.\]conf;\s*\}/m',
            $conf
        );
    }

    /**
     * @return array{id: int, transport: string, listen_ip: string, listen_port: int, upstream_host: string, upstream_port: int, is_generated: bool}
     */
    private function rule(int $id, string $transport, string $ip, int $port, string $host, int $upstreamPort): array
    {
        return [
            'id' => $id,
            'transport' => $transport,
            'listen_ip' => $ip,
            'listen_port' => $port,
            'upstream_host' => $host,
            'upstream_port' => $upstreamPort,
            'is_generated' => false,
        ];
    }
}
