<?php

namespace Tests\Unit\System;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Models\Domain;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The account vhost is the first hop the engine controls. It must hand the app
 * the real client in X-Forwarded-For, not append to whatever the visitor sent;
 * behind the *.panelalpha.online front that client comes from the front's header.
 */
class VhostForwardedForTest extends TestCase
{
    private const TEMPLATES = ['dind/virtualHost-nginx-proxy.blade.php', 'virtualHost-nginx-proxy.blade.php'];

    public function test_no_vhost_forwards_the_visitor_supplied_x_forwarded_for(): void
    {
        foreach (self::TEMPLATES as $template) {
            $vhost = $this->render($template, []);
            $this->assertStringNotContainsString('$proxy_add_x_forwarded_for', $vhost, $template);
            $this->assertMatchesRegularExpression('/X-Forwarded-For\s+\$remote_addr;/', $vhost, $template);
        }

        foreach ([
            'core/app/System/Services/Webserver/NginxProxy.php',
            'core/app/System/Project/SitePasswordProtection.php',
        ] as $file) {
            $source = (string) file_get_contents(dirname(base_path()) . '/' . $file);
            $this->assertStringNotContainsString('proxy_add_x_forwarded_for', $source, $file);
        }
    }

    public function test_the_app_lite_vhosts_forward_the_peer_not_the_visitors_header(): void
    {
        foreach (['virtualHost-nginx-app-lite.blade.php', 'virtualHost-nginx-proxy-app-lite.blade.php'] as $template) {
            $vhost = Blade::render((string) file_get_contents(dirname(base_path()) . '/templates/' . $template), [
                'client_area' => true,
                'admin_area' => true,
                'ca_port' => 8443,
                'aa_port' => 9443,
                'ca_proxy_host' => 'client-area',
                'ca_proxy_port' => 80,
                'aa_proxy_host' => 'admin-area',
                'aa_proxy_port' => 80,
                'ca_server_name' => 'client.example.test',
                'aa_server_name' => 'admin.example.test',
                'ips_v4' => [],
                'ips_v6' => [],
                'ssl_cert_file' => '/tmp/app.pem',
                'ssl_cert_key_file' => '/tmp/app.key',
            ]);
            $this->assertStringNotContainsString('$proxy_add_x_forwarded_for', $vhost, $template);
            $this->assertSame(2, preg_match_all('/X-Forwarded-For\s+\$remote_addr;/', $vhost), $template);
        }
    }

    public function test_a_customer_domain_trusts_no_forwarded_header(): void
    {
        foreach (self::TEMPLATES as $template) {
            $vhost = $this->render($template, []);
            $this->assertStringNotContainsString('set_real_ip_from', $vhost, $template);
            $this->assertStringNotContainsString('real_ip_header', $vhost, $template);
        }
    }

    public function test_every_server_block_behind_the_front_takes_the_client_from_it(): void
    {
        foreach (self::TEMPLATES as $template) {
            $vhost = $this->render($template, ['159.69.7.81', '2a01:4f8::/64']);
            $blocks = preg_split('/^\s*server \{/m', $vhost) ?: [];
            array_shift($blocks);
            $this->assertNotEmpty($blocks, $template);
            foreach ($blocks as $block) {
                $this->assertStringContainsString('set_real_ip_from 159.69.7.81;', $block, $template);
                $this->assertStringContainsString('set_real_ip_from 2a01:4f8::/64;', $block, $template);
                $this->assertSame(1, substr_count($block, 'real_ip_header X-Forwarded-For;'), $template);
            }
        }
    }

    public function test_front_addresses_keep_only_ips_and_cidrs(): void
    {
        config(['connect.front_addresses' => '159.69.7.81, 10.0.0.0/8 2a01:4f8::1,bad;host 1.2.3.4/33 ::/129 159.69.7.81']);

        $this->assertSame(['159.69.7.81', '10.0.0.0/8', '2a01:4f8::1'], PanelAlphaConnect::frontAddresses());
    }

    public function test_only_a_domain_the_front_serves_trusts_it(): void
    {
        config(['connect.front_addresses' => '159.69.7.81']);

        $online = new Domain(['domain' => 'shop-4f2a.panelalpha.online']);
        $aliased = new Domain(['domain' => 'shop.example.com', 'details' => ['aliases' => ['Shop.PanelAlpha.Online.']]]);
        $customer = new Domain(['domain' => 'shop.example.com', 'details' => ['aliases' => ['www.shop.example.com']]]);
        $lookalike = new Domain(['domain' => 'evilpanelalpha.online']);

        $this->assertSame(['159.69.7.81'], PanelAlphaConnect::trustedFrontsFor($online));
        $this->assertSame(['159.69.7.81'], PanelAlphaConnect::trustedFrontsFor($aliased));
        $this->assertSame([], PanelAlphaConnect::trustedFrontsFor($customer));
        $this->assertSame([], PanelAlphaConnect::trustedFrontsFor($lookalike));
    }

    /**
     * @param list<string> $fronts
     */
    private function render(string $template, array $fronts): string
    {
        $path = dirname(base_path()) . '/templates/' . $template;

        return Blade::render((string) file_get_contents($path), [
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
            'proxy_extra' => [['listen_port' => 3001, 'host' => 'shop', 'port' => 3000, 'protocol' => 'http']],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
            'trusted_fronts' => $fronts,
        ]);
    }
}
