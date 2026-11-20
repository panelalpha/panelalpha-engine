<?php

namespace Tests\Integration\System\Webserver;

use App\System;
use App\System\Services\Webserver\Nginx;
use Tests\TestCase;

/** The app-lite proxy variables every vhost template gets, read from the env config. */
class AppProxyVarsTest extends TestCase
{
    private const KEYS = [
        'APP_LITE_CA_PORT', 'APP_LITE_CA_DOMAIN', 'APP_LITE_PROXY_CA_HOST', 'APP_LITE_PROXY_CA_PORT',
        'APP_LITE_AA_PORT', 'APP_LITE_AA_DOMAIN', 'APP_LITE_PROXY_AA_HOST', 'APP_LITE_PROXY_AA_PORT',
        'APP_LITE_PROXY_WS_HOST', 'APP_LITE_PROXY_WS_PORT', 'APP_LITE_PROXY_WS_REWRITE_HOST', 'APP_LITE_PROXY_WS_REWRITE_PORT',
    ];

    public function test_nothing_configured_disables_both_areas(): void
    {
        $this->configure([]);

        $this->assertSame([
            'client_area' => '',
            'ca_port' => '',
            'ca_server_name' => '_',
            'ca_proxy_host' => '',
            'ca_proxy_port' => '',
            'admin_area' => '',
            'aa_port' => '',
            'aa_server_name' => '_',
            'aa_proxy_host' => '',
            'aa_proxy_port' => '',
            'ws_proxy_host' => '',
            'ws_proxy_port' => '',
            'ws_rewrite_proxy_host' => '',
            'ws_rewrite_proxy_port' => '',
        ], $this->vars());
    }

    public function test_every_value_is_passed_on_as_a_string_in_template_order(): void
    {
        $this->configure([
            'APP_LITE_CA_PORT' => '8443',
            'APP_LITE_CA_DOMAIN' => 'client.example.test',
            'APP_LITE_PROXY_CA_HOST' => 'client-area',
            'APP_LITE_PROXY_CA_PORT' => 8080,
            'APP_LITE_AA_PORT' => 9443,
            'APP_LITE_AA_DOMAIN' => 'admin.example.test',
            'APP_LITE_PROXY_AA_HOST' => 'admin-area',
            'APP_LITE_PROXY_AA_PORT' => '8081',
            'APP_LITE_PROXY_WS_HOST' => 'ws',
            'APP_LITE_PROXY_WS_PORT' => '6001',
            'APP_LITE_PROXY_WS_REWRITE_HOST' => 'ws-rewrite',
            'APP_LITE_PROXY_WS_REWRITE_PORT' => '6002',
        ]);

        $this->assertSame([
            'client_area' => '1',
            'ca_port' => '8443',
            'ca_server_name' => 'client.example.test',
            'ca_proxy_host' => 'client-area',
            'ca_proxy_port' => '8080',
            'admin_area' => '1',
            'aa_port' => '9443',
            'aa_server_name' => 'admin.example.test',
            'aa_proxy_host' => 'admin-area',
            'aa_proxy_port' => '8081',
            'ws_proxy_host' => 'ws',
            'ws_proxy_port' => '6001',
            'ws_rewrite_proxy_host' => 'ws-rewrite',
            'ws_rewrite_proxy_port' => '6002',
        ], $this->vars());
    }

    public function test_a_port_alone_enables_an_area_on_the_catch_all_name(): void
    {
        $this->configure(['APP_LITE_CA_PORT' => '8443', 'APP_LITE_AA_DOMAIN' => 'admin.example.test', 'APP_LITE_PROXY_WS_PORT' => 'not-a-port']);

        $vars = $this->vars();

        $this->assertSame(['1', '8443', '_'], [$vars['client_area'], $vars['ca_port'], $vars['ca_server_name']]);
        $this->assertSame(['1', '', 'admin.example.test'], [$vars['admin_area'], $vars['aa_port'], $vars['aa_server_name']]);
        $this->assertSame('', $vars['ws_proxy_port']);
    }

    /** @param array<string, mixed> $values */
    private function configure(array $values): void
    {
        foreach (self::KEYS as $key) {
            config(['env.' . $key => $values[$key] ?? null]);
        }
    }

    /** @return array<string, string> */
    private function vars(): array
    {
        $method = new \ReflectionMethod(Nginx::class, 'getAppProxyVars');

        return $method->invoke(new Nginx(new System()));
    }
}
