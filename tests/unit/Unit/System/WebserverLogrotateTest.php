<?php

namespace Tests\Unit\System;

use App\System\Services\Webserver\NginxProxy;
use Tests\TestCase;

class WebserverLogrotateTest extends TestCase
{
    /**
     * The postrotate named a compose service `webserver` that no longer
     * exists, so the webserver never reopened its logs: it kept writing into
     * the rotated file, which statistics did not read. The broker's
     * rootbroker.logrotation reopens them in sites-http.
     */
    public function test_postrotate_reopens_logs_in_sites_http_which_the_webserver_compose_file_defines(): void
    {
        $root = dirname(base_path());
        $variants = [
            'nginx-proxy' => 'nginx',
            'nginx' => 'nginx',
            'apache' => 'apache',
        ];

        foreach ($variants as $variant => $server) {
            $conf = (string) file_get_contents("{$root}/templates/config/logrotate/{$variant}.conf");
            $compose = (string) file_get_contents("{$root}/docker-compose.yml-{$variant}");

            $this->assertStringContainsString("/webserver-logs/{$variant}/*/*.log", $conf, $variant);
            $this->assertMatchesRegularExpression(
                '#^\s*PYTHONPATH=/usr/local/lib/panelalpha python3 -m rootbroker\.logrotation reopen '.$server.'( |$)#m',
                $conf,
                $variant
            );
            $this->assertMatchesRegularExpression('/^  sites-http:$/m', $compose, $variant);
        }
    }

    /**
     * The proxy's own access.log and error.log sit where the per-site pattern does not reach.
     */
    public function test_every_log_the_nginx_proxy_templates_write_is_rotated(): void
    {
        $root = dirname(base_path());
        $rotated = $this->rotatedPaths("{$root}/templates/config/logrotate/nginx-proxy.conf");
        $templates = [
            'templates/webserver-config/nginx-proxy/nginx.conf',
            'templates/webserver-nginx-proxy.blade.php',
            'templates/virtualHost-nginx-proxy.blade.php',
            'templates/virtualHost-nginx-proxy-app-lite.blade.php',
            'templates/dind/virtualHost-nginx-proxy.blade.php',
        ];

        $logs = [];
        foreach ($templates as $template) {
            preg_match_all(
                '#(?:access|error)_log\s+(/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/\S+\.log)#',
                str_replace('{{ $domain }}', 'shop.example.test', (string) file_get_contents("{$root}/{$template}")),
                $m
            );
            $logs = [...$logs, ...$m[1]];
        }

        $this->assertContains('/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/access.log', $logs);
        $this->assertContains('/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/error.log', $logs);
        $this->assertContains('/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/shop.example.test/bytes.log', $logs);
        foreach (array_unique($logs) as $log) {
            $matching = array_filter($rotated, fn (string $pattern): bool => fnmatch($pattern, $log, FNM_PATHNAME));
            $this->assertNotEmpty($matching, "{$log} matches no path of the nginx-proxy logrotate file");
        }
    }

    /**
     * The servers' own access.log and error.log sit where the per-site pattern does not reach.
     */
    public function test_the_nginx_and_apache_servers_own_logs_are_rotated(): void
    {
        $root = dirname(base_path());
        $cases = [
            'nginx' => ['templates/webserver-nginx.blade.php', 'templates/webserver-config/nginx/nginx.conf'],
            'apache' => ['templates/webserver-apache.blade.php', 'templates/webserver-config/apache/httpd.conf'],
        ];

        foreach ($cases as $variant => $templates) {
            $rotated = $this->rotatedPaths("{$root}/templates/config/logrotate/{$variant}.conf");
            $logs = [];
            foreach ($templates as $template) {
                preg_match_all(
                    '#(?:access_log|error_log|CustomLog|ErrorLog)\s+(/opt/panelalpha/shared-hosting/webserver-logs/'.$variant.'/\S+\.log)#',
                    (string) file_get_contents("{$root}/{$template}"),
                    $m
                );
                $logs = [...$logs, ...$m[1]];
            }

            $this->assertContains("/opt/panelalpha/shared-hosting/webserver-logs/{$variant}/access.log", $logs, $variant);
            $this->assertContains("/opt/panelalpha/shared-hosting/webserver-logs/{$variant}/error.log", $logs, $variant);
            foreach (array_unique($logs) as $log) {
                $matching = array_filter($rotated, fn (string $pattern): bool => fnmatch($pattern, $log, FNM_PATHNAME));
                $this->assertNotEmpty($matching, "{$log} matches no path of the {$variant} logrotate file");
            }
        }
    }

    /**
     * A proxy rule's logs sit on the mounted nginx-proxy log path, so they rotate and survive a recreate.
     */
    public function test_a_proxy_rules_logs_are_rotated_with_the_nginx_proxy_logs(): void
    {
        $root = dirname(base_path());
        $rotated = $this->rotatedPaths("{$root}/templates/config/logrotate/nginx-proxy.conf");

        $rule = NginxProxy::renderHttpProxyRule([
            'id' => 7,
            'listen_ip' => '*',
            'listen_port' => 8081,
            'server_names' => ['a.example.com'],
            'upstream_protocol' => 'http',
            'upstream_host' => 'acct-7',
            'upstream_port' => 3000,
            'ssl_enabled' => false,
        ]);

        preg_match_all('#(?:access_log|error_log)\s+(\S+)\.log#', $rule, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $base) {
            $log = "{$base}.log";
            $this->assertStringStartsWith('/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/', $log, $log);
            $matching = array_filter($rotated, fn (string $pattern): bool => fnmatch($pattern, $log, FNM_PATHNAME));
            $this->assertNotEmpty($matching, "{$log} matches no path of the nginx-proxy logrotate file");
        }
    }

    public function test_the_php_error_log_is_capped_and_truncated_in_place(): void
    {
        $root = dirname(base_path());
        $conf = (string) file_get_contents("{$root}/templates/config/logrotate/php.conf");

        // copytruncate: the account's worker owns the file and logrotate runs
        // as root, so a created replacement would not be writable by it.
        $this->assertStringContainsString('/opt/panelalpha/shared-hosting/users/*/log/php/*.log', $conf);
        $this->assertMatchesRegularExpression('/^\s*size 10M$/m', $conf);
        $this->assertMatchesRegularExpression('/^\s*rotate 3$/m', $conf);
        $this->assertMatchesRegularExpression('/^\s*copytruncate$/m', $conf);
    }

    /**
     * Written as root outside any quota; a second rotation in a month needs its own name or logrotate skips it.
     */
    public function test_the_webserver_logs_are_capped_by_size_with_a_name_per_rotation(): void
    {
        $root = dirname(base_path());
        foreach (['apache-users', 'apache', 'nginx', 'nginx-proxy'] as $variant) {
            $conf = (string) file_get_contents("{$root}/templates/config/logrotate/{$variant}.conf");

            $this->assertMatchesRegularExpression('/^\s*monthly$/m', $conf, $variant);
            $this->assertMatchesRegularExpression('/^\s*maxsize 100M$/m', $conf, $variant);
            $this->assertMatchesRegularExpression('/^\s*rotate 24$/m', $conf, $variant);
            $this->assertMatchesRegularExpression('/^\s*dateformat -%Y-%m-%d-%H-%M-%S$/m', $conf, $variant);
        }
    }

    public function test_install_refreshes_the_webserver_logrotate_files_on_existing_hosts(): void
    {
        $root = dirname(base_path());
        foreach (['scripts/installer.sh', 'scripts/bootstrap-from-source.sh'] as $script) {
            $this->assertMatchesRegularExpression(
                '#^\s*cp \S*templates/config/logrotate/\{apache,apache-users,nginx,nginx-proxy,php\}\.conf \S*config/logrotate/$#m',
                (string) file_get_contents("{$root}/{$script}"),
                $script
            );
        }
    }

    /**
     * @return list<string>
     */
    private function rotatedPaths(string $conf): array
    {
        $head = strstr((string) file_get_contents($conf), '{', true);

        return preg_split('/\s+/', trim((string) $head), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
