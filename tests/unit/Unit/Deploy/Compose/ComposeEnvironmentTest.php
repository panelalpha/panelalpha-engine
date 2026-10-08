<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\Runtime\Php\PhpEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What an account may set on the environment its own application runs with.
 *
 * The case this exists for: symfony/demo deployed on the `php` platform, whose
 * `APP_ENV=production` is Laravel's value and one of exactly three Symfony
 * refuses to boot on. `env_vars` put `APP_ENV=prod` in `.env`, compose's
 * `environment:` block outranked the file, and the account had no way to
 * correct a variable it never named.
 */
class ComposeEnvironmentTest extends TestCase
{
    public function test_the_account_outranks_what_the_platform_generated(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => PhpEnvironment::for([], null, false)],
            [],
            ['APP_ENV' => 'prod']
        );

        $this->assertSame('prod', $decision['env']['APP_ENV']);
        $this->assertSame('stderr', $decision['env']['LOG_CHANNEL'], 'the rest of the platform env survives');
    }

    public function test_the_app_config_outranks_the_platform_and_the_account_outranks_it(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => ['APP_ENV' => 'production', 'LOG_LEVEL' => 'warning']],
            ['APP_ENV' => 'prod', 'LOG_LEVEL' => 'notice'],
            ['LOG_LEVEL' => 'debug']
        );

        $this->assertSame('prod', $decision['env']['APP_ENV'], 'the app config had the only word on it');
        $this->assertSame('debug', $decision['env']['LOG_LEVEL'], 'the account had the last word');
    }

    /**
     * An empty field in the panel means "keep what the project shipped", which
     * is the rule ProjectEnvironment already applies to the same values on
     * their way into .env. An app config is a file its author wrote, so an empty
     * string there is a value.
     */
    public function test_an_empty_account_value_keeps_the_generated_one(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => ['APP_ENV' => 'production']],
            [],
            ['APP_ENV' => '']
        );

        $this->assertSame('production', $decision['env']['APP_ENV']);
    }

    public function test_an_empty_app_config_value_is_a_value(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => ['LOG_CHANNEL' => 'stderr']],
            ['LOG_CHANNEL' => ''],
            []
        );

        $this->assertSame('', $decision['env']['LOG_CHANNEL']);
    }

    /**
     * PA_DOCROOT is read by the generated Apache config and PA_DEPLOY_PHASE
     * decides install from upgrade; an account that moved either would get a
     * container that misbehaves with nothing in the log to say why.
     */
    public function test_the_entrypoints_own_variables_are_not_overridable(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => ['PA_DOCROOT' => '/app/public', 'PA_DEPLOY_PHASE' => 'install']],
            ['PA_DOCROOT' => '/app'],
            ['PA_DEPLOY_PHASE' => 'upgrade']
        );

        $this->assertSame('/app/public', $decision['env']['PA_DOCROOT']);
        $this->assertSame('install', $decision['env']['PA_DEPLOY_PHASE']);
    }

    /**
     * These name the port compose publishes and the health check probes. An
     * account that moved them would get a container nothing can reach.
     */
    public function test_the_published_port_is_not_overridable(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => []],
            [],
            ['PORT' => '9000', 'HOST' => '127.0.0.1', 'HOSTNAME' => 'localhost', 'DB_PASSWORD' => 'theirs']
        );

        $this->assertSame(['DB_PASSWORD' => 'theirs'], $decision['env']);
    }

    public function test_a_decision_with_nothing_to_override_is_untouched(): void
    {
        $decision = ['env' => null, 'image' => 'nginx:alpine'];

        $this->assertSame($decision, ComposeEnvironment::layer($decision, [], []));
    }

    public function test_the_override_reaches_the_generated_compose_file(): void
    {
        $yaml = DeployCompose::framework(
            ComposeEnvironment::layer(
                ['runtime' => 'php', 'image' => 'php:8.4-apache', 'env' => ['APP_ENV' => 'production']],
                [],
                ['APP_ENV' => 'prod']
            ),
            8000,
            'https://phpapp.example.test'
        );

        $environment = Yaml::parse($yaml)['services']['app']['environment'];

        $this->assertSame('prod', $environment['APP_ENV']);
        $this->assertSame('8000', $environment['PORT'], 'the engine still names the port it publishes');
    }

    /**
     * A recipe's `${PA_PUBLIC_HOST}` reached the generated compose
     * literally and Compose interpolated it to ''.
     */
    public function test_the_public_address_placeholders_are_resolved_for_a_generated_compose(): void
    {
        $decision = ComposeEnvironment::withPublicAddress(
            ['env' => [
                'SESSION_DOMAIN' => '${PA_PUBLIC_HOST}',
                'SANCTUM_STATEFUL_DOMAINS' => '${PA_PUBLIC_HOST},localhost',
                'MAIL_LINK' => '${PA_PUBLIC_URL:-http://localhost}/mail',
                'APP_ENV' => 'production',
            ]],
            'https://crater.example.test/'
        );

        $environment = Yaml::parse(DeployCompose::framework($decision, 8000, 'https://crater.example.test'))['services']['app']['environment'];

        $this->assertSame('crater.example.test', $environment['SESSION_DOMAIN']);
        $this->assertSame('crater.example.test,localhost', $environment['SANCTUM_STATEFUL_DOMAINS']);
        $this->assertSame('https://crater.example.test/mail', $environment['MAIL_LINK']);
        $this->assertSame('production', $environment['APP_ENV']);
    }

    public function test_without_a_public_url_the_env_is_left_alone(): void
    {
        $decision = ['env' => ['SESSION_DOMAIN' => '${PA_PUBLIC_HOST}']];

        $this->assertSame($decision, ComposeEnvironment::withPublicAddress($decision, null));
        $this->assertSame(['runtime' => 'php'], ComposeEnvironment::withPublicAddress(['runtime' => 'php'], 'https://a.test'));
    }
}
