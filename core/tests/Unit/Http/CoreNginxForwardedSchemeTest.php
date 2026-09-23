<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * engine#273: realip rewrites REMOTE_ADDR to the client before PHP runs, so
 * TrustProxies never sees the proxy and ignores X-Forwarded-Proto/Port. Core's
 * nginx has to hand PHP the scheme and port itself, or every redirect core
 * builds is http:// and the engine origin loses :2011.
 */
class CoreNginxForwardedSchemeTest extends TestCase
{
    private function config(): string
    {
        $path = __DIR__ . '/../../../../config/core/nginx.conf';
        if (!is_file($path)) {
            $this->markTestSkipped('config/core/nginx.conf is not beside core/ here');
        }

        return (string) file_get_contents($path);
    }

    public function test_the_forwarded_scheme_is_taken_only_from_hops_realip_trusts(): void
    {
        $conf = $this->config();

        $this->assertMatchesRegularExpression('/geo \$realip_remote_addr \$pa_trusted_hop \{[^}]*127\.0\.0\.1 1;[^}]*172\.16\.0\.0\/12 1;/s', $conf);
        $this->assertStringContainsString('"1:https" on;', $conf);
    }

    public function test_php_gets_the_scheme_and_port_after_the_stock_params(): void
    {
        $conf = $this->config();
        $include = strpos($conf, 'include fastcgi_params;');
        $https = strpos($conf, 'fastcgi_param HTTPS $pa_https if_not_empty;');
        $port = strpos($conf, 'fastcgi_param SERVER_PORT $pa_port;');

        $this->assertNotFalse($include);
        $this->assertNotFalse($https);
        $this->assertNotFalse($port);
        $this->assertGreaterThan($include, $https, 'the stock HTTPS param must not come last');
        $this->assertGreaterThan($include, $port, 'the stock SERVER_PORT param must not come last');
    }

    public function test_the_api_port_forwards_its_scheme_and_keeps_its_port_in_host(): void
    {
        $conf = $this->config();
        $api = substr($conf, (int) strpos($conf, 'listen 2011 ssl;'));
        $api = substr($api, 0, (int) strpos($api, 'location /csf-web-ui'));

        $this->assertStringContainsString('proxy_set_header Host $host:$server_port;', $api);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $api);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Port $server_port;', $api);
    }
}
