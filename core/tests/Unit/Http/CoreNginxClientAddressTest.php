<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * On a CSF host :2011 reached core through docker-proxy, from the
 * bridge gateway, and that gateway sat inside the trusted 172.16.0.0/12 -- so
 * an internet client's X-Forwarded-For was believed. Only the two proxies that
 * really set the header may be trusted, and :2011 must start the chain itself.
 */
class CoreNginxClientAddressTest extends TestCase
{
    private function file(string $relative): string
    {
        $path = __DIR__ . '/../../../../' . $relative;
        if (!is_file($path)) {
            $this->markTestSkipped("$relative is not beside core/ here");
        }

        return (string) file_get_contents($path);
    }

    /** The body of the server block that listens on $listen, braces balanced. */
    private function server(string $conf, string $listen): string
    {
        $at = strpos($conf, $listen);
        $this->assertNotFalse($at, "no server with $listen");
        $start = strrpos(substr($conf, 0, $at), 'server {');
        $depth = 0;
        for ($i = $start; $i < strlen($conf); $i++) {
            if ($conf[$i] === '{') {
                $depth++;
            } elseif ($conf[$i] === '}' && --$depth === 0) {
                return substr($conf, $start, $i - $start + 1);
            }
        }
        $this->fail("unbalanced server with $listen");
    }

    /** Directives only, comments dropped. */
    private function directives(string $conf): string
    {
        return (string) preg_replace('/#.*$/m', '', $conf);
    }

    public function test_realip_trusts_the_loopback_and_the_written_gateway_only(): void
    {
        $conf = $this->directives($this->file('config/core/nginx.conf'));
        $php = $this->server($conf, 'listen 80 default_server;');

        preg_match_all('/set_real_ip_from\s+([^;]+);/', $conf, $all);
        $this->assertSame(['127.0.0.1'], $all[1], 'no range may be trusted');
        $this->assertStringContainsString('set_real_ip_from 127.0.0.1;', $php);
        $this->assertStringContainsString('include /etc/nginx/pa-trusted-proxy*.conf;', $php);
    }

    public function test_the_api_port_keeps_its_peer_and_starts_the_chain(): void
    {
        $conf = $this->directives($this->file('config/core/nginx.conf'));
        $api = $this->server($conf, 'listen 2011 ssl;');

        // No realip here: $remote_addr on :2011 is whoever connected.
        $this->assertStringNotContainsString('set_real_ip_from', $api);
        $this->assertStringNotContainsString('include /etc/nginx/pa-trusted-proxy', $api);
        // Nothing sits in front of :2011, so a client's own header is dropped.
        $this->assertStringNotContainsString('$proxy_add_x_forwarded_for', $conf);
        $this->assertSame(1, substr_count($api, 'proxy_set_header X-Forwarded-For $remote_addr;'));
    }

    public function test_the_api_port_refuses_the_phpmyadmin_credentials_route(): void
    {
        $api = $this->server($this->directives($this->file('config/core/nginx.conf')), 'listen 2011 ssl;');

        $this->assertMatchesRegularExpression(
            '#location ~\* \^/api/mysql/phpmyadmin-sso-token \{\s*default_type application/json;\s*return 404 #',
            $api
        );
        $this->assertTrue(
            (bool) preg_match('#^/api/mysql/phpmyadmin-sso-token#i', '/API/mysql/phpmyadmin-sso-token/'),
            'the refusal must not be dodged by case or a trailing slash'
        );
    }

    public function test_the_entrypoint_writes_the_gateway_both_realip_and_the_map_read(): void
    {
        $entry = $this->file('dockerfiles/entrypoint-core.sh');

        $this->assertStringContainsString('write_trusted_proxy_conf', $entry);
        $this->assertStringContainsString('/etc/nginx/pa-trusted-proxy.conf', $entry);
        $this->assertStringContainsString('set_real_ip_from %s;\nset $pa_gateway %s;\n', $entry);
        $this->assertLessThan(
            strpos($entry, 'exec s6-svscan'),
            strpos($entry, "\nwrite_trusted_proxy_conf\n"),
            'the file has to exist before s6 starts nginx'
        );
    }

    /** ufw reloads only its own chains, so Docker's DNAT for :2011 is never flushed. */
    public function test_the_firewall_leaves_dockers_dnat_alone(): void
    {
        $ufw = $this->file('scripts/firewall/ufw.sh');
        $this->assertStringContainsString('s/^MANAGE_BUILTINS=.*/MANAGE_BUILTINS=no/', $ufw);
        $this->assertStringNotContainsString('-t nat -F', $ufw);
        $this->assertFileDoesNotExist(__DIR__ . '/../../../../scripts/csf-publish-core.sh');
    }
}
