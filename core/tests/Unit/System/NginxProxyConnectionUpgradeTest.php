<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * `Connection: upgrade` without `Upgrade:` is a bogus upgrade request: gevent's
 * pywsgi hands the app the raw socket (every POST hangs) and undici rejects it.
 * The vhosts send it only when the client asked to upgrade.
 */
class NginxProxyConnectionUpgradeTest extends TestCase
{
    public function test_no_vhost_sends_connection_upgrade_unconditionally(): void
    {
        foreach (['dind/virtualHost-nginx-proxy.blade.php', 'virtualHost-nginx-proxy.blade.php'] as $name) {
            $vhost = (string) file_get_contents(dirname(base_path()) . '/templates/' . $name);

            $this->assertDoesNotMatchRegularExpression('/proxy_set_header\s+Connection\s+"?upgrade"?\s*;/i', $vhost, $name);
            $this->assertGreaterThan(0, substr_count($vhost, 'proxy_set_header Connection $pa_connection_upgrade;'), $name);
        }
    }

    public function test_every_proxied_dind_block_follows_the_client_upgrade(): void
    {
        $vhost = Blade::render(
            (string) file_get_contents(dirname(base_path()) . '/templates/dind/virtualHost-nginx-proxy.blade.php'),
            [
                'user' => 'shop',
                'domain' => 'shop.example.test',
                'aliases' => [],
                'ips_v4' => [],
                'ips_v6' => [],
                'relative_document_root' => '/shop.example.test/public_html',
                'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
                'proxy_https' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
                'proxy_extra' => [['listen_port' => 3001, 'host' => 'shop', 'port' => 3000, 'protocol' => 'http']],
                'ssl_enabled' => true,
                'ssl_cert_pem_file' => '/tmp/shop.pem',
                'ssl_cert_key_file' => '/tmp/shop.key',
            ]
        );

        $this->assertSame(3, substr_count($vhost, 'proxy_set_header Connection $pa_connection_upgrade;'));
        // Each proxying server block defines the variable itself.
        $this->assertSame(3, preg_match_all('/set \$pa_connection_upgrade "";\s*if \(\$http_upgrade\) \{\s*set \$pa_connection_upgrade upgrade;\s*\}/', $vhost));
    }

    /**
     * The vhosts must not depend on a variable only a newer nginx.conf defines:
     * a vhost rendered before the main config is re-rendered would make nginx
     * refuse its whole configuration ("unknown variable").
     */
    public function test_no_vhost_needs_a_variable_from_the_main_config(): void
    {
        foreach (['dind/virtualHost-nginx-proxy.blade.php', 'virtualHost-nginx-proxy.blade.php'] as $name) {
            $vhost = (string) file_get_contents(dirname(base_path()) . '/templates/' . $name);

            $this->assertStringNotContainsString('$connection_upgrade', $vhost, $name);
        }
    }
}
