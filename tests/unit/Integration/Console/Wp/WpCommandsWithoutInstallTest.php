<?php

namespace Tests\Integration\Console\Wp;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Every wordpress:* command on a PHP hosting project without a main domain says so and exits 1 before any WP-CLI call. */
class WpCommandsWithoutInstallTest extends TestCase
{
    use InMemoryDatabase;

    private const NO_MAIN_DOMAIN = "Project 'site' has no main domain.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('site');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function commands(): array
    {
        return [
            'auto-update get' => ['wordpress:auto-update:get', []],
            'auto-update set' => ['wordpress:auto-update:set', ['core' => 'minor', 'plugins' => 'on', 'themes' => 'off']],
            'cache flush' => ['wordpress:cache:flush', []],
            'config create' => ['wordpress:config:create', ['dbname' => 'wp', 'dbuser' => 'wp', '--dbpass' => 'secret', '--dbprefix' => 'wp_', '--skip-check' => true]],
            'config delete' => ['wordpress:config:delete', ['constant' => 'WP_DEBUG']],
            'config get' => ['wordpress:config:get', ['constant' => 'WP_DEBUG', '--type' => 'all']],
            'config list' => ['wordpress:config:list', []],
            'config set' => ['wordpress:config:set', ['constant' => 'WP_DEBUG', 'value' => 'true', '--raw' => true]],
            'core check-update' => ['wordpress:core:check-update', []],
            'core download' => ['wordpress:core:download', ['--locale' => 'pl_PL', '--wp-version' => '6.7.1']],
            'core install' => ['wordpress:core:install', ['url' => 'https://site.example.test', 'title' => 'Site', 'admin_user' => 'admin', 'admin_email' => 'admin@example.test', 'admin_password' => 'secret', '--skip-email' => true]],
            'core status' => ['wordpress:core:status', []],
            'core update' => ['wordpress:core:update', ['version' => '6.7.1']],
            'database export' => ['wordpress:database:export', ['file' => '/home/site/dump.sql', '--tables' => 'wp_posts,wp_options']],
            'database import' => ['wordpress:database:import', ['file' => '/home/site/dump.sql']],
            'language set' => ['wordpress:language:set', ['language' => 'pl_PL']],
            'maintenance get' => ['wordpress:maintenance:get', []],
            'maintenance set' => ['wordpress:maintenance:set', ['enabled' => 'true']],
            'option get' => ['wordpress:option:get', ['option' => 'home']],
            'option list' => ['wordpress:option:list', []],
            'option set' => ['wordpress:option:set', ['option' => 'blogname', 'value' => 'Site']],
            'plugin activate' => ['wordpress:plugin:activate', ['slug' => 'akismet']],
            'plugin deactivate' => ['wordpress:plugin:deactivate', ['slug' => 'akismet']],
            'plugin delete' => ['wordpress:plugin:delete', ['slug' => 'akismet']],
            'plugin get' => ['wordpress:plugin:get', ['slug' => 'akismet', '--fields' => 'name,status']],
            'plugin install' => ['wordpress:plugin:install', ['slug' => 'akismet']],
            'plugin is-installed' => ['wordpress:plugin:is-installed', ['slug' => 'akismet']],
            'plugin list' => ['wordpress:plugin:list', ['--fields' => 'name', '--status' => 'active']],
            'plugin search' => ['wordpress:plugin:search', ['term' => 'cache', '--per-page' => '5']],
            'plugin update' => ['wordpress:plugin:update', ['slug' => 'akismet']],
            'search-replace' => ['wordpress:search-replace', ['search' => 'http://old.test', 'replacement' => 'https://new.test', 'extra' => ['wp_posts']]],
            'theme activate' => ['wordpress:theme:activate', ['slug' => 'twentytwentyfour']],
            'theme delete' => ['wordpress:theme:delete', ['slug' => 'twentytwentyfour']],
            'theme get' => ['wordpress:theme:get', ['slug' => 'twentytwentyfour']],
            'theme install' => ['wordpress:theme:install', ['slug' => 'twentytwentyfour', '--force' => true]],
            'theme is-installed' => ['wordpress:theme:is-installed', ['slug' => 'twentytwentyfour']],
            'theme list' => ['wordpress:theme:list', []],
            'theme search' => ['wordpress:theme:search', ['term' => 'blog', '--fields' => 'name', '--per-page' => '3']],
            'theme update' => ['wordpress:theme:update', ['slug' => 'twentytwentyfour']],
            'application-password create' => ['wordpress:user:application-password:create', ['user' => 'admin', 'name' => 'panel', '--app-id' => 'panel-app']],
            'application-password delete' => ['wordpress:user:application-password:delete', ['user' => 'admin', 'uuid' => '6f1c1e4a-1c1e-4a6f-8c1e-1c1e4a6f8c1e']],
            'application-password list' => ['wordpress:user:application-password:list', ['user' => 'admin']],
            'user create' => ['wordpress:user:create', ['login' => 'editor', 'email' => 'editor@example.test', 'password' => 'correct-horse-battery', '--role' => 'editor', '--display-name' => 'Ed', '--first-name' => 'Ed', '--last-name' => 'Itor']],
            'user delete' => ['wordpress:user:delete', ['user' => 'editor', '--reassign' => 'admin']],
            'user list' => ['wordpress:user:list', ['--role' => 'administrator']],
            'user password' => ['wordpress:user:password', ['user' => 'admin', 'password' => 'correct-horse-battery']],
            'user sso' => ['wordpress:user:sso', ['user' => 'admin']],
            'user update' => ['wordpress:user:update', ['user' => 'admin', '--email' => 'new@example.test', '--role' => 'editor']],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('commands')]
    public function test_a_project_without_a_main_domain_is_refused(string $command, array $arguments): void
    {
        $this->artisan($command, ['username' => 'site', ...$arguments])
            ->expectsOutput(self::NO_MAIN_DOMAIN)
            ->doesntExpectOutputToContain('{')
            ->assertExitCode(1);
    }

    public function test_maintenance_set_refuses_a_value_that_is_not_a_boolean(): void
    {
        $this->artisan('wordpress:maintenance:set', ['username' => 'site', 'enabled' => 'maybe'])
            ->expectsOutput('enabled must be a boolean (1/0/true/false).')
            ->assertExitCode(1);
    }

    public function test_core_download_refuses_a_malformed_locale_or_version(): void
    {
        $this->artisan('wordpress:core:download', ['username' => 'site', '--locale' => 'pl;rm'])
            ->expectsOutput('Invalid --locale: pl;rm')
            ->assertExitCode(1);

        $this->artisan('wordpress:core:download', ['username' => 'site', '--wp-version' => '6.7 && id'])
            ->expectsOutput('Invalid --wp-version: 6.7 && id')
            ->assertExitCode(1);
    }

    public function test_the_raw_command_is_refused_without_a_main_domain(): void
    {
        $this->artisan('wordpress:command', ['username' => 'site', 'arg' => ['option', 'get', 'home'], '--skip-plugins' => true])
            ->expectsOutput(self::NO_MAIN_DOMAIN)
            ->assertExitCode(1);
    }

    public function test_the_raw_command_refuses_a_service_on_php_hosting(): void
    {
        $this->artisan('wordpress:command', ['username' => 'site', 'arg' => ['core', 'version'], '--service' => 'wordpress'])
            ->expectsOutput("service applies only to WordPress in the project's own containers.")
            ->assertExitCode(1);
    }
}
