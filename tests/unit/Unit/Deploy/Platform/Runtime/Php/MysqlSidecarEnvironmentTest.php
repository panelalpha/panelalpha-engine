<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\Php\DatabaseSettings;
use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The database the sidecar provisions, reached from the app that shares its
 * network namespace.
 *
 * Firefly III is why this exists. The engine emitted a `db` sidecar with
 * `network_mode: service:app`, so the database is at 127.0.0.1:3306 and the
 * name `db` resolves to nothing -- but the app's environment came from the
 * repository's own `.env.example`, which is written for a workstation compose
 * with a real `db` service and says `DB_HOST=db`. The deploy then died on
 * `php_network_getaddresses: getaddrinfo for db failed` next to a database
 * that was up and healthy.
 *
 * The fix is a layer of compose `environment:`, which outranks the `env_file:`
 * carrying `.env`. Tested at both ends: the variables themselves, and that
 * they end up where compose will give them precedence.
 */
class MysqlSidecarEnvironmentTest extends TestCase
{
    /** @return array{connection: string, host: string, port: string, database: string, username: string, password: string} */
    private function settings(): array
    {
        return [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'firefly_app',
            'username' => 'firefly_app',
            'password' => 's3cret',
        ];
    }

    public function test_the_sidecar_is_reached_on_loopback_and_not_by_name(): void
    {
        $env = MysqlSidecar::connectionEnvironment(DatabaseSettings::fromArray($this->settings()));

        $this->assertSame('127.0.0.1', $env['DB_HOST']);
        $this->assertSame('3306', $env['DB_PORT']);
        // The network namespace is shared, so a socket path the example might
        // carry would point at a socket this container never creates.
        $this->assertSame('', $env['DB_SOCKET']);
    }

    /**
     * The credentials are the ones the container was provisioned with.
     * Restating them costs nothing and stops a `.env.example` placeholder
     * password from outliving the database it was never the password for.
     */
    public function test_the_connection_restates_the_credentials_it_was_given(): void
    {
        $env = MysqlSidecar::connectionEnvironment(DatabaseSettings::fromArray($this->settings()));

        $this->assertSame('mysql', $env['DB_CONNECTION']);
        $this->assertSame('firefly_app', $env['DB_DATABASE']);
        $this->assertSame('firefly_app', $env['DB_USERNAME']);
        $this->assertSame('s3cret', $env['DB_PASSWORD']);
    }

    /** A blank port or password is the sidecar's own default, not an empty one. */
    public function test_a_blank_port_falls_back_to_the_mysql_default(): void
    {
        $env = MysqlSidecar::connectionEnvironment(DatabaseSettings::fromArray([
            'connection' => 'mysql',
            'database' => 'app',
            'username' => 'root',
        ]));

        $this->assertSame('3306', $env['DB_PORT']);
        $this->assertSame('', $env['DB_PASSWORD']);
    }

    /**
     * The layer has to land in `environment:`. In `env_file:` it would be the
     * `.env.example` again, and compose gives `environment:` precedence --
     * which is the whole mechanism.
     */
    public function test_the_connection_reaches_the_app_service_as_environment_not_env_file(): void
    {
        $decision = [
            'runtime' => PlatformManifest::RUNTIME_PHP,
            'image' => 'panelalpha/php:8.5-apache-pa12345678',
            'env' => MysqlSidecar::connectionEnvironment(DatabaseSettings::fromArray($this->settings())),
        ];

        $parsed = Yaml::parse(DeployCompose::framework($decision, 8000));
        $app = $parsed['services']['app'];

        $this->assertSame('127.0.0.1', $app['environment']['DB_HOST']);
        $this->assertSame(['.env'], $app['env_file']);
    }

    /**
     * The path PhpStrategy takes when it adds the sidecar: `.env.example`'s
     * `DB_HOST=db` is in the generated env, and has to lose.
     */
    public function test_adding_the_sidecar_repoints_an_example_db_host_at_loopback(): void
    {
        $decision = MysqlSidecar::withSidecar(
            ['runtime' => PlatformManifest::RUNTIME_PHP, 'env' => ['DB_HOST' => 'db', 'APP_ENV' => 'production']],
            array_merge($this->settings(), ['host' => 'db'])
        );

        $this->assertSame('127.0.0.1', $decision['env']['DB_HOST']);
        $this->assertSame('production', $decision['env']['APP_ENV']);
        $this->assertSame('service:app', $decision['sidecars']['db']['network_mode']);
        $this->assertArrayHasKey('dbdata', $decision['volumes']);

        $app = Yaml::parse(DeployCompose::framework($decision + ['image' => 'php:8.3-apache'], 8000))['services']['app'];
        $this->assertSame('127.0.0.1', $app['environment']['DB_HOST']);
    }

    /**
     * The sidecar still shares the namespace -- the loopback pin is only
     * correct while it does. A future change that gives it its own network
     * has to re-point the app at the service name.
     */
    public function test_the_service_still_shares_the_apps_network_namespace(): void
    {
        $service = MysqlSidecar::service($this->settings());

        $this->assertSame('service:app', $service['network_mode']);
        $this->assertSame('firefly_app', $service['environment']['MYSQL_DATABASE']);
        $this->assertSame('firefly_app', $service['environment']['MYSQL_USER']);
    }
}
