<?php

namespace Tests\Unit\System;

use Tests\TestCase;

class WebserverLogrotateTest extends TestCase
{
    /**
     * The postrotate named a compose service `webserver` that no longer
     * exists, so the webserver never reopened its logs: it kept writing into
     * the rotated file, which statistics did not read.
     */
    public function test_postrotate_reopens_logs_through_a_service_the_webserver_compose_file_defines(): void
    {
        $root = dirname(base_path());
        $variants = [
            'nginx-proxy' => 'nginx -s reopen',
            'nginx' => 'nginx -s reopen',
            'apache' => 'apachectl -k graceful',
        ];

        foreach ($variants as $variant => $reopen) {
            $conf = (string) file_get_contents("{$root}/templates/config/logrotate/{$variant}.conf");
            $compose = (string) file_get_contents("{$root}/docker-compose.yml-{$variant}");

            $this->assertStringContainsString("/webserver-logs/{$variant}/*/*.log", $conf, $variant);
            $this->assertSame(
                1,
                preg_match('/docker compose -f \S+ exec -T (\S+) ' . preg_quote($reopen, '/') . '/', $conf, $m),
                $variant
            );
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($m[1], '/') . ':$/m', $compose, $variant);
        }
    }

    public function test_install_refreshes_the_webserver_logrotate_files_on_existing_hosts(): void
    {
        $root = dirname(base_path());
        foreach (['scripts/installer.sh', 'scripts/bootstrap-from-source.sh'] as $script) {
            $this->assertMatchesRegularExpression(
                '#^\s*cp \S*templates/config/logrotate/\{apache,nginx,nginx-proxy\}\.conf \S*config/logrotate/$#m',
                (string) file_get_contents("{$root}/{$script}"),
                $script
            );
        }
    }
}
