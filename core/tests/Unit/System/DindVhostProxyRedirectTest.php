<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * An absolute Location built inside the account (Apache's trailing-slash 301,
 * a .htaccess Redirect) names the container's scheme and port, which the
 * visitor cannot reach. The DinD vhost rewrites those to relative (engine#177).
 */
class DindVhostProxyRedirectTest extends TestCase
{
    public function test_every_proxied_block_rewrites_the_container_port_away(): void
    {
        $vhost = $this->render();

        [$http, $https, $extra] = $this->blocks($vhost);

        $this->assertStringContainsString('proxy_redirect http://$host:8000/ /;', $http);
        $this->assertStringContainsString('proxy_redirect http://$host:8000/ /;', $https);
        $this->assertStringContainsString('proxy_redirect http://$host:3000/ /;', $extra);
    }

    public function test_only_the_tls_block_treats_plain_http_to_this_host_as_a_downgrade(): void
    {
        [$http, $https] = $this->blocks($this->render());

        $this->assertStringContainsString('proxy_redirect http://$host/ /;', $https);
        $this->assertStringNotContainsString('proxy_redirect http://$host/ /;', $http);
    }

    public function test_an_https_upstream_is_matched_on_its_own_scheme(): void
    {
        [, $https] = $this->blocks($this->render(httpsProtocol: 'https', httpsPort: 8443));

        $this->assertStringContainsString('proxy_redirect https://$host:8443/ /;', $https);
    }

    public function test_a_static_vhost_gets_no_proxy_redirect(): void
    {
        $vhost = $this->render(proxied: false);

        $this->assertStringNotContainsString('proxy_redirect', $vhost);
    }

    /**
     * @return list<string> the :80 block, the :443 block, then any extra listeners
     */
    private function blocks(string $vhost): array
    {
        $parts = preg_split('/^\s*server \{/m', $vhost) ?: [];
        array_shift($parts);

        return array_values($parts);
    }

    private function render(bool $proxied = true, string $httpsProtocol = 'http', int $httpsPort = 8000): string
    {
        $path = dirname(base_path()) . '/templates/dind/virtualHost-nginx-proxy.blade.php';
        if (!is_file($path)) {
            $this->markTestSkipped("No vhost template at {$path}.");
        }

        return Blade::render((string) file_get_contents($path), [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'relative_document_root' => '/shop.example.test/public_html',
            'proxy_http' => $proxied ? ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'] : null,
            'proxy_https' => $proxied ? ['host' => 'shop', 'port' => $httpsPort, 'protocol' => $httpsProtocol] : null,
            'proxy_extra' => $proxied ? [['listen_port' => 3001, 'host' => 'shop', 'port' => 3000, 'protocol' => 'http']] : [],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
        ]);
    }
}
