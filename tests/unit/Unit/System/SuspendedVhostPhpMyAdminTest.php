<?php

namespace Tests\Unit\System;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * engine#856 item 3: suspension returns 503 for a project's site, but
 * `location /phpmyadmin` sits outside `location /` and kept proxying for
 * anyone with a login token or an open session, so suspension did not cut
 * database access. Both vhost templates must refuse phpMyAdmin while
 * suspended, on both the :80 and :443 server blocks.
 */
class SuspendedVhostPhpMyAdminTest extends TestCase
{
    private const TEMPLATES = [
        'dind/virtualHost-nginx-proxy.blade.php',
        'virtualHost-nginx-proxy.blade.php',
    ];

    /** @return array<string, mixed> */
    private function vars(bool $suspended): array
    {
        return [
            'user' => 'shop',
            'userhost' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'suspended' => $suspended,
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
            'site_password_enabled' => false,
            'http_acme_challenges_enabled' => false,
            'app_port' => 8000,
            'app_ssl_port' => 8000,
        ];
    }

    private function render(string $template, bool $suspended): string
    {
        $path = dirname(base_path()) . '/templates/' . $template;

        return Blade::render((string) file_get_contents($path), $this->vars($suspended));
    }

    /** @return list<string> the `location /phpmyadmin` bodies, braces balanced */
    private function phpMyAdminBlocks(string $vhost): array
    {
        $blocks = [];
        $offset = 0;

        while (($at = strpos($vhost, 'location /phpmyadmin {', $offset)) !== false) {
            $start = $at + strlen('location /phpmyadmin {');
            $depth = 1;
            $i = $start;
            for (; $i < strlen($vhost); $i++) {
                if ($vhost[$i] === '{') {
                    $depth++;
                } elseif ($vhost[$i] === '}' && --$depth === 0) {
                    break;
                }
            }
            $blocks[] = substr($vhost, $start, $i - $start);
            $offset = $i;
        }

        return $blocks;
    }

    public function test_a_suspended_vhost_refuses_phpmyadmin_in_every_server_block(): void
    {
        foreach (self::TEMPLATES as $template) {
            $blocks = $this->phpMyAdminBlocks($this->render($template, true));

            $this->assertNotEmpty($blocks, "{$template} rendered no phpMyAdmin block");
            foreach ($blocks as $i => $block) {
                $this->assertStringContainsString('return 503;', $block, "{$template} block {$i} still proxies while suspended");
                $this->assertStringNotContainsString('proxy_pass', $block, "{$template} block {$i} still proxies while suspended");
            }
        }
    }

    public function test_an_active_vhost_still_proxies_phpmyadmin(): void
    {
        foreach (self::TEMPLATES as $template) {
            $blocks = $this->phpMyAdminBlocks($this->render($template, false));

            $this->assertNotEmpty($blocks, "{$template} rendered no phpMyAdmin block");
            foreach ($blocks as $i => $block) {
                $this->assertStringNotContainsString('return 503;', $block, "{$template} block {$i} refuses an active project");
                $this->assertStringContainsString('proxy_pass http://phpmyadmin-users.shared-hosting.palocal;', $block);
            }
        }
    }
}
