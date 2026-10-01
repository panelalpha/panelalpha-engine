<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * proxy_pass talks HTTP/1.1 to the account and drops gRPC's trailers, so gRPC
 * requests on the TLS vhost go to grpc_pass (HTTP/2) instead.
 */
class DindVhostGrpcTest extends TestCase
{
    public function test_grpc_on_the_tls_block_goes_to_grpc_pass(): void
    {
        [$http, $https, $extra] = $this->blocks($this->render());

        $this->assertStringContainsString('grpc_pass grpc://$proxyupstream:8000;', $https);
        $this->assertMatchesRegularExpression('/if \(\$http_content_type ~\* "[^"]+"\) \{\s*grpc_pass /', $https);
        // Every other request still takes proxy_pass.
        $this->assertStringContainsString('proxy_pass http://$proxyupstream:8000;', $https);
        // Plain :80 and extra listeners have no HTTP/2, so gRPC cannot arrive there.
        $this->assertStringNotContainsString('grpc_pass', $http);
        $this->assertStringNotContainsString('grpc_pass', $extra);
    }

    public function test_a_tls_upstream_gets_grpcs(): void
    {
        [, $https] = $this->blocks($this->render('https', 8443));

        $this->assertStringContainsString('grpc_pass grpcs://$proxyupstream:8443;', $https);
    }

    public function test_the_match_takes_grpc_but_not_grpc_web(): void
    {
        [, $https] = $this->blocks($this->render());
        preg_match('/if \(\$http_content_type ~\* "([^"]+)"\)/', $https, $m);
        $pattern = '/' . str_replace('/', '\/', $m[1]) . '/i';

        foreach (['application/grpc', 'application/grpc+proto', 'application/grpc; charset=utf-8', 'Application/GRPC'] as $type) {
            $this->assertMatchesRegularExpression($pattern, $type, $type);
        }
        foreach (['application/grpc-web', 'application/grpc-web+proto', 'application/grpc-web-text', 'application/json', ''] as $type) {
            $this->assertDoesNotMatchRegularExpression($pattern, $type, $type);
        }
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

    private function render(string $httpsProtocol = 'http', int $httpsPort = 8000): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()) . '/templates/dind/virtualHost-nginx-proxy.blade.php'), [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'relative_document_root' => '/shop.example.test/public_html',
            'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_https' => ['host' => 'shop', 'port' => $httpsPort, 'protocol' => $httpsProtocol],
            'proxy_extra' => [['listen_port' => 3001, 'host' => 'shop', 'port' => 3000, 'protocol' => 'http']],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
        ]);
    }
}
