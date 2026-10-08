<?php

namespace Tests\Unit\System;

use Tests\TestCase;

class NginxCombinedLogFormatTest extends TestCase
{
    public function test_nginx_configs_rely_on_builtin_combined_and_keep_main_with_x_forwarded_for(): void
    {
        $files = [
            dirname(base_path()) . '/templates/webserver-nginx.blade.php',
            dirname(base_path()) . '/templates/webserver-nginx-proxy.blade.php',
            dirname(base_path()) . '/templates/webserver-config/nginx/nginx.conf',
            dirname(base_path()) . '/templates/webserver-config/nginx-proxy/nginx.conf',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file, $file);
            $contents = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/log_format\s+combined\b/',
                $contents,
                $file
            );
            $this->assertMatchesRegularExpression(
                "/log_format\\s+main[\\s\\S]*http_x_forwarded_for/",
                $contents,
                $file
            );
            $this->assertMatchesRegularExpression(
                "/access_log\\s+\\S+\\s+main;/",
                $contents,
                $file
            );
        }
    }

    public function test_nginx_vhosts_write_access_logs_as_combined(): void
    {
        $files = [
            dirname(base_path()) . '/templates/virtualHost-nginx.blade.php',
            dirname(base_path()) . '/templates/virtualHost-nginx-proxy.blade.php',
        ];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringContainsString('access.log combined', $contents, $file);
            $this->assertStringNotContainsString('access.log main', $contents, $file);
        }
    }

    public function test_litespeed_and_openlitespeed_vhosts_do_not_override_combined_access_format(): void
    {
        $ls = (string) file_get_contents(dirname(base_path()) . '/templates/virtualHostConfig-litespeed.blade.php');
        $ols = (string) file_get_contents(dirname(base_path()) . '/templates/virtualHostConfig-openlitespeed.blade.php');

        $this->assertStringContainsString('<fileName>/opt/panelalpha/shared-hosting/webserver-logs/litespeed/{{ $domain }}/access.log</fileName>', $ls);
        $this->assertStringNotContainsString('<logFormat>', $ls);
        $this->assertStringContainsString('accesslog /opt/panelalpha/shared-hosting/webserver-logs/openlitespeed/{{ $domain }}/access.log {', $ols);
        $this->assertStringNotContainsString('logFormat', $ols);
    }
}
