<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\System\Project\PhpHosting\ReadOnlyConfigMounts;
use Tests\TestCase;

/**
 * Root in a PHP hosting container is root on the host, so the config the engine writes for the account is mounted
 * read-only; the account's own data, logs and crontab stay writable.
 */
class ReadOnlyConfigMountsTest extends TestCase
{
    private const TEMPLATES = ['docker-compose.yml', 'docker-compose.yml-fpm', 'docker-compose.yml-fpm-apache', 'docker-compose.yml-ls'];

    private const COMPOSE = <<<'YAML'
    services:
      php:
        volumes:
          - /home/alice/:/home/alice/
          - /home/alice/:/var/www/
          - ./apache-sites:/etc/apache2/sites-enabled
          - ./apache-conf/remoteip.conf:/etc/apache2/conf-enabled/remoteip.conf
          - ./ssl-certs:/etc/apache2/ssl-certs
          - ./log:/var/log
          - ./crontabs/www-data:/var/spool/cron/crontabs/alice
          - ./msmtp/msmtprc:/etc/msmtprc
          - ./services/:/etc/s6/account/:ro
          - ./entrypoint-init.d/:/entrypoint-init.d/
          - ./php/ext/:/etc/php/ext/
          - ./php/8.3/default.ini:/etc/php/8.3/fpm/conf.d/90-panelalpha-default.ini
          - ./php/8.3/fpm/pool.d/:/etc/php/8.3/fpm/pool.d/
    YAML;

    public function test_the_engine_config_mounts_become_read_only(): void
    {
        $restricted = ReadOnlyConfigMounts::restrict(self::COMPOSE);

        foreach ([
            './apache-sites:/etc/apache2/sites-enabled:ro',
            './apache-conf/remoteip.conf:/etc/apache2/conf-enabled/remoteip.conf:ro',
            './ssl-certs:/etc/apache2/ssl-certs:ro',
            './msmtp/msmtprc:/etc/msmtprc:ro',
            './entrypoint-init.d/:/entrypoint-init.d/:ro',
            './php/ext/:/etc/php/ext/:ro',
            './php/8.3/default.ini:/etc/php/8.3/fpm/conf.d/90-panelalpha-default.ini:ro',
            './php/8.3/fpm/pool.d/:/etc/php/8.3/fpm/pool.d/:ro',
        ] as $mount) {
            $this->assertStringContainsString("- {$mount}\n", $restricted."\n");
        }
    }

    public function test_the_accounts_own_mounts_stay_writable(): void
    {
        $restricted = ReadOnlyConfigMounts::restrict(self::COMPOSE);

        foreach (['/home/alice/:/var/www/', './log:/var/log', './crontabs/www-data:/var/spool/cron/crontabs/alice', './services/:/etc/s6/account/:ro'] as $mount) {
            $this->assertStringContainsString("- {$mount}\n", $restricted);
        }
        $this->assertStringNotContainsString(':ro:ro', $restricted);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $once = ReadOnlyConfigMounts::restrict(self::COMPOSE);

        $this->assertSame($once, ReadOnlyConfigMounts::restrict($once));
    }

    /** What an update does to an existing project is what a new project's template already says. */
    public function test_every_shipped_template_already_mounts_the_engine_config_read_only(): void
    {
        foreach (self::TEMPLATES as $name) {
            $template = (string) file_get_contents(dirname(__DIR__, 6)."/templates/user/default/project/{$name}.blade.php");

            $this->assertSame($template, ReadOnlyConfigMounts::restrict($template), $name);
            $this->assertMatchesRegularExpression('/- \.\/msmtp\/msmtprc:\/etc\/msmtprc:ro$/m', $template, $name);
        }
    }
}
