<?php

namespace Tests\Integration\System;

use Illuminate\Support\Facades\Blade;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A container domain answers HTTPS on each of its ports with one TLS server, and :80 sends visitors to the main port
 * once 443 is not one of them. Every port the main config does not know as a vhost's gets a default server of its own.
 */
class DindVhostHttpsPortsTest extends TestCase
{
    public function test_a_domain_that_set_no_ports_answers_on_443_as_before(): void
    {
        [$http, $https] = $this->blocks($this->render([]));

        $this->assertSame(['443'], $this->listenPorts($https));
        $this->assertStringContainsString('proxy_pass http://$proxyupstream:8000;', $http);
    }

    public function test_every_port_is_a_listener_of_the_one_tls_server(): void
    {
        $vhost = $this->render(['https_ports' => [443, 8443], 'https_redirect_port' => 8443, 'ips_v4' => [TestNetwork::public2(7)]]);
        [$http, $https] = $this->blocks($vhost);

        $this->assertSame([TestNetwork::public2(7).':443', TestNetwork::public2(7).':8443'], $this->listenPorts($https));
        $this->assertStringNotContainsString('return 301', $http, 'with 443 still served, :80 serves the app as before');
    }

    public function test_off_443_the_plain_http_server_sends_visitors_to_the_main_port(): void
    {
        [$http, $https] = $this->blocks($this->render([
            'https_ports' => [8443],
            'https_redirect_port' => 8443,
            'http_acme_challenges_enabled' => true,
            'http_acme_challenges_dir' => '/tmp/acme',
        ]));

        $this->assertSame(['8443'], $this->listenPorts($https));
        $this->assertStringContainsString('return 301 https://$host:8443$request_uri;', $http);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $http);
    }

    public function test_forcing_https_names_the_main_port(): void
    {
        [$http] = $this->blocks($this->render(['https_ports' => [443, 9443], 'https_redirect_port' => 9443, 'force_https_redirect' => true]));

        $this->assertStringContainsString('return 301 https://$host:9443$request_uri;', $http);
    }

    public function test_the_main_config_gives_each_extra_port_a_default_server(): void
    {
        $conf = Blade::render($this->file('templates/webserver-nginx-proxy.blade.php'), [
            'modsecurity_enabled' => false,
            'ips_v4' => [TestNetwork::public2(7)],
            'ips_v6' => [],
            'fallback_proxy' => false,
            'ssl_cert_file' => '/tmp/server.cert',
            'ssl_cert_key_file' => '/tmp/server.key',
            'extra_https_ports' => [8443],
        ]);

        $this->assertStringContainsString('listen '.TestNetwork::public2(7).':8443 ssl default_server;', $conf);
        $this->assertStringContainsString('listen '.TestNetwork::public2(7).':443 ssl default_server;', $conf);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function render(array $vars): string
    {
        return Blade::render($this->file('templates/dind/virtualHost-nginx-proxy.blade.php'), $vars + [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'relative_document_root' => '/shop.example.test/public_html',
            'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_https' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_extra' => [],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
        ]);
    }

    /** @return list<string> the :80 block, the TLS block, then any extra listeners */
    private function blocks(string $vhost): array
    {
        $parts = preg_split('/^\s*server \{/m', $vhost) ?: [];
        array_shift($parts);

        return array_values($parts);
    }

    /** @return list<string> */
    private function listenPorts(string $block): array
    {
        preg_match_all('/^\s*listen\s+(\S+)\s+ssl;/m', $block, $matches);

        return $matches[1];
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(dirname(base_path()) . '/' . $path);
    }
}
