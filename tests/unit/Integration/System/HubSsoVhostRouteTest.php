<?php

namespace Tests\Integration\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Every app vhost hands a posted Hub login ticket to the engine before the app sees it, on HTTP and HTTPS alike. */
class HubSsoVhostRouteTest extends TestCase
{
    private const TEMPLATES = ['dind/virtualHost-nginx-proxy.blade.php', 'virtualHost-nginx-proxy.blade.php'];

    public function test_each_server_block_routes_only_a_post_to_the_projects_internal_route(): void
    {
        foreach (self::TEMPLATES as $template) {
            $vhost = $this->render($template);

            $this->assertSame(2, substr_count($vhost, 'location = /panelalpha-sso/hub {'), $template);
            preg_match_all('/location = \/panelalpha-sso\/hub \{(.*?)\n\s*\}/s', $vhost, $blocks);
            foreach ($blocks[1] as $block) {
                $this->assertStringContainsString('limit_except POST { deny all; }', $block, $template);
                $this->assertStringContainsString('proxy_pass http://$enginehost/api/internal/hub-sso/shop;', $block, $template);
                $this->assertMatchesRegularExpression('/X-Forwarded-For\s+\$remote_addr;/', $block, $template);
            }
        }
    }

    private function render(string $template): string
    {
        return Blade::render((string) file_get_contents(dirname(base_path()).'/templates/'.$template), [
            'user' => 'shop',
            'userhost' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'suspended' => false,
            'redirect_url' => null,
            'force_https_redirect' => false,
            'relative_document_root' => '/shop.example.test/public_html',
            'app_port' => 8000,
            'app_ssl_port' => 8000,
            'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_https' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_extra' => [],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
            'trusted_fronts' => [],
        ]);
    }
}
