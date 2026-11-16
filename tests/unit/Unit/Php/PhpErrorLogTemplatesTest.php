<?php

namespace Tests\Unit\Php;

use App\System\Project\PhpHosting\PhpErrorLogConfig;
use Tests\TestCase;

/**
 * The PHP error log path lives in the version's default.ini, loaded before the
 * project's custom.ini, so a tenant can still redirect it. The FPM pool only
 * captures what the workers write outside PHP's own logger.
 */
class PhpErrorLogTemplatesTest extends TestCase
{
    public function test_every_php_version_writes_its_errors_to_the_project_log_dir(): void
    {
        foreach ($this->phpTemplates('default.ini') as $version => $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringContainsString('log_errors = On', $contents, $version);
            $this->assertStringContainsString('error_log = /var/log/php/php_error.log', $contents, $version);
        }
    }

    public function test_from_php_8_4_no_jit_buffer_is_reserved_next_to_ioncube(): void
    {
        $checked = 0;
        foreach (['user/default/project/php', 'user-config/php'] as $dir) {
            foreach ($this->phpTemplates('default.ini', $dir) as $version => $file) {
                if (version_compare($version, '8.4', '<')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^opcache\.jit_buffer_size = 0$/m', (string) file_get_contents($file), $file);
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $checked);
    }

    /** A saved document root's user INI reaches the site in seconds, out of reach of the root's own .user.ini. */
    public function test_every_php_version_re_reads_a_document_roots_user_ini_within_seconds(): void
    {
        foreach ($this->phpTemplates('default.ini') as $version => $file) {
            $this->assertMatchesRegularExpression(
                '/^user_ini\\.cache_ttl = 3$/m',
                (string) file_get_contents($file),
                $version
            );
        }
    }

    public function test_every_fpm_pool_catches_worker_output(): void
    {
        foreach ($this->phpTemplates('fpm/pool.d/www.conf.blade.php') as $version => $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringContainsString('catch_workers_output = yes', $contents, $version);
            $this->assertStringContainsString('decorate_workers_output = no', $contents, $version);
        }
    }

    /** The pool carries the short user INI cache too, as an admin value a project's own ini cannot raise. */
    public function test_every_fpm_pool_holds_the_short_user_ini_cache(): void
    {
        $checked = 0;
        foreach ($this->phpTemplates('fpm/pool.d/www.conf.blade.php') as $version => $file) {
            $this->assertMatchesRegularExpression(
                '/^php_admin_value\[user_ini\.cache_ttl\] = '.PhpErrorLogConfig::USER_INI_CACHE_TTL.'$/m',
                (string) file_get_contents($file),
                $version
            );
            $checked++;
        }

        $this->assertGreaterThanOrEqual(6, $checked);
    }

    public function test_the_error_log_is_not_admin_locked_in_the_pool(): void
    {
        foreach ($this->phpTemplates('fpm/pool.d/www.conf.blade.php') as $version => $file) {
            $this->assertStringNotContainsString('php_admin_value[error_log]', (string) file_get_contents($file), $version);
            $this->assertStringNotContainsString('php_admin_flag[log_errors]', (string) file_get_contents($file), $version);
        }
    }

    /**
     * @return array<string, string> PHP version => template path
     */
    private function phpTemplates(string $relative, string $dir = 'user/default/project/php'): array
    {
        $files = glob(dirname(base_path()) . "/templates/{$dir}/*/{$relative}") ?: [];
        $this->assertNotEmpty($files);

        $templates = [];
        foreach ($files as $file) {
            $this->assertSame(1, preg_match('#/php/([^/]+)/#', $file, $m), $file);
            $templates[$m[1]] = $file;
        }

        return $templates;
    }
}
