<?php

namespace Tests\Integration\System;

use App\System\Services\Webserver\NginxProxy;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A custom HTTP proxy rule's vhost, byte for byte. */
class NginxProxyHttpRuleTextTest extends TestCase
{
    private const BASE = [
        'id' => 7,
        'listen_ip' => '*',
        'listen_port' => 8081,
        'server_names' => ['a.example.com', 'b.example.com'],
        'upstream_protocol' => 'http',
        'upstream_host' => 'acct-7',
        'upstream_port' => 3000,
        'ssl_enabled' => false,
    ];

    public function test_a_plain_rule_on_every_address(): void
    {
        $expected = $this->upstream() . <<<'NGINX'
server {
    listen 8081;
    server_name a.example.com b.example.com;

    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/proxy-rule-8081.access.log;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/proxy-rule-8081.error.log;



NGINX . $this->location('http');

        $this->assertSame($expected, NginxProxy::renderHttpProxyRule(self::BASE));

        $withoutProtocol = self::BASE;
        unset($withoutProtocol['upstream_protocol']);
        $this->assertSame($expected, NginxProxy::renderHttpProxyRule($withoutProtocol));
        $this->assertSame(
            str_replace('listen 8081;', 'listen 8081 ssl;', $expected),
            NginxProxy::renderHttpProxyRule(['ssl_enabled' => true, 'ssl_cert_pem_file' => '/c/cert.pem'] + self::BASE)
        );
    }

    public function test_a_tls_rule_on_one_ipv6_address(): void
    {
        $vars = [
            'listen_ip' => '2001:db8::5',
            'listen_port' => 8443,
            'upstream_protocol' => 'https',
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/c/cert.pem',
            'ssl_cert_key_file' => '/c/key.pem',
            'ssl_cert_sha256' => 'abc123',
        ] + self::BASE;

        $this->assertSame($this->upstream() . <<<'NGINX'
server {
    listen [2001:db8::5]:8443 ssl;
    server_name a.example.com b.example.com;

    access_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/proxy-rule-2001:db8::5:8443.access.log;
    error_log /opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/proxy-rule-2001:db8::5:8443.error.log;
    # certificate sha256 abc123
    ssl_certificate /c/cert.pem;
    ssl_certificate_key /c/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;


NGINX . $this->location('https'), NginxProxy::renderHttpProxyRule($vars, true, [TestNetwork::DOCUMENTATION_1, '2001:db8::5']));
    }

    private function upstream(): string
    {
        $dns = TestNetwork::ENGINE_DNS_PROXY;

        return <<<NGINX
upstream upstream-7-acct-7-3000 {
  resolver {$dns} valid=30s;
  zone upstreams 64k;
  server acct-7:3000 resolve;
}

NGINX;
    }

    private function location(string $scheme): string
    {
        return <<<NGINX
    location / {
        proxy_pass {$scheme}://upstream-7-acct-7-3000;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$server_name;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_pass_header X-Accel-Buffering;
    }
}
NGINX;
    }
}
