<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SidecarCredentials.
 *
 * The class has no Laravel dependencies, so this test extends the plain
 * PHPUnit TestCase — no app boot required. Engine data (ports, drivers,
 * schemes) comes from the real resources/sidecars/dialects.php catalogue,
 * so these tests double as a check that postgres/redis stay wired the way
 * the rest of the deploy pipeline expects.
 */
class SidecarCredentialsTest extends TestCase
{
    public function test_env_for_sidecar_derives_laravel_and_dsn_variables_for_a_known_driver_engine(): void
    {
        $service = [
            'image' => 'postgres:16-alpine',
            'environment' => [
                'POSTGRES_USER' => 'app',
                'POSTGRES_PASSWORD' => 'secret',
                'POSTGRES_DB' => 'appdb',
            ],
        ];

        $env = SidecarCredentials::envForSidecar('database', $service, [5432]);

        $this->assertSame('database', $env['POSTGRES_HOST']);
        $this->assertSame('5432', $env['POSTGRES_PORT']);
        $this->assertSame('app', $env['POSTGRES_USER']);
        $this->assertSame('secret', $env['POSTGRES_PASSWORD']);
        $this->assertSame('appdb', $env['POSTGRES_DB']);
        $this->assertSame('postgres://app:secret@database:5432/appdb', $env['POSTGRES_URL']);

        // Laravel-shaped spellings, derived from the same dialect entry.
        $this->assertSame('pgsql', $env['DB_CONNECTION']);
        $this->assertSame('database', $env['DB_HOST']);
        $this->assertSame('5432', $env['DB_PORT']);
        $this->assertSame('appdb', $env['DB_DATABASE']);
        $this->assertSame('app', $env['DB_USERNAME']);
        $this->assertSame('secret', $env['DB_PASSWORD']);
        $this->assertSame('postgres://app:secret@database:5432/appdb', $env['DATABASE_URL']);
    }

    public function test_env_for_sidecar_url_encodes_special_characters_in_credentials(): void
    {
        $service = [
            'image' => 'postgres:16-alpine',
            'environment' => [
                'POSTGRES_USER' => 'app',
                'POSTGRES_PASSWORD' => 'p@ss w/ord',
                'POSTGRES_DB' => 'appdb',
            ],
        ];

        $env = SidecarCredentials::envForSidecar('database', $service, [5432]);

        $this->assertSame(
            'postgres://app:p%40ss%20w%2Ford@database:5432/appdb',
            $env['POSTGRES_URL']
        );
    }

    public function test_env_for_sidecar_no_driver_engine_gets_framework_env_but_no_db_fields(): void
    {
        // redis has a scheme and a port but no `driver` entry: it should get a
        // connection URL and its own dialect env, never Laravel's DB_* keys.
        $env = SidecarCredentials::envForSidecar('cache', ['image' => 'redis:7-alpine']);

        $this->assertSame('cache', $env['REDIS_HOST']);
        $this->assertSame('6379', $env['REDIS_PORT']);
        $this->assertSame('redis://cache:6379/0', $env['REDIS_URL']);
        $this->assertSame('redis', $env['QUEUE_CONNECTION']);
        $this->assertSame('redis', $env['CACHE_STORE']);
        $this->assertSame('redis', $env['CACHE_DRIVER']);
        $this->assertArrayNotHasKey('DB_CONNECTION', $env);
        $this->assertArrayNotHasKey('DATABASE_URL', $env);
    }

    public function test_env_for_sidecar_unknown_engine_still_gets_host_and_credentials_under_its_own_prefix(): void
    {
        // An engine with no dialect entry still reaches the application: host,
        // port (when known) and its own declared variables, nothing invented.
        $service = [
            'image' => 'ghcr.io/acme/foo:1',
            'environment' => [
                'FOO_USER' => 'x',
                'FOO_PASSWORD' => 'y',
            ],
        ];

        $env = SidecarCredentials::envForSidecar('foo', $service);

        $this->assertSame([
            'FOO_HOST' => 'foo',
            'FOO_USER' => 'x',
            'FOO_PASSWORD' => 'y',
        ], $env);
    }

    public function test_pin_sidecar_credentials_resolves_unset_compose_defaults_for_init_variables(): void
    {
        $service = [
            'image' => 'postgres:16-alpine',
            'environment' => [
                'POSTGRES_USER=${POSTGRES_USER:-app}',
                'POSTGRES_PASSWORD=${POSTGRES_PASSWORD}',
                'POSTGRES_DB=mydb',
            ],
        ];

        $pinned = SidecarCredentials::pinSidecarCredentials('database', $service);

        $this->assertSame([
            'POSTGRES_USER' => 'app',
            'POSTGRES_PASSWORD' => 'app',
            'POSTGRES_DB' => 'mydb',
        ], $pinned['environment']);
    }

    public function test_pin_sidecar_credentials_gives_an_unset_password_the_accounts_own(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $service = [
            'image' => 'mariadb:11',
            'environment' => ['MYSQL_PASSWORD' => '${DB_PASSWORD}'],
        ];

        $env = SidecarCredentials::pinSidecarCredentials('db', $service, [], $passwords)['environment'];

        // Names stay `app`; only secrets change.
        $this->assertSame('app', $env['MYSQL_USER']);
        $this->assertSame('app', $env['MYSQL_DATABASE']);
        $this->assertSame($passwords->for('MYSQL_PASSWORD'), $env['MYSQL_PASSWORD']);
        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $env['MYSQL_ROOT_PASSWORD']);
        $this->assertNotSame($env['MYSQL_PASSWORD'], $env['MYSQL_ROOT_PASSWORD']);
    }

    public function test_pin_sidecar_credentials_keeps_a_compose_default_password(): void
    {
        $service = [
            'image' => 'postgres:16',
            'environment' => ['POSTGRES_PASSWORD' => '${POSTGRES_PASSWORD:-chosen}'],
        ];

        $env = SidecarCredentials::pinSidecarCredentials('db', $service, [], SidecarPasswords::derived('s'))['environment'];

        $this->assertSame('chosen', $env['POSTGRES_PASSWORD']);
    }

    public function test_pin_sidecar_credentials_is_a_no_op_for_an_engine_without_a_driver(): void
    {
        $service = ['image' => 'redis:7-alpine', 'environment' => ['FOO' => 'bar']];

        $this->assertSame($service, SidecarCredentials::pinSidecarCredentials('cache', $service));
    }

    public function test_env_from_dropped_app_service_only_copies_self_hosting_flags(): void
    {
        $service = [
            'environment' => [
                'SELF_HOSTED=true',
                'SECRET_KEY=irrelevant',
            ],
        ];

        $this->assertSame(
            ['SELF_HOSTED' => 'true'],
            SidecarCredentials::envFromDroppedAppService($service)
        );
        $this->assertSame([], SidecarCredentials::envFromDroppedAppService(['environment' => ['OTHER=1']]));
    }

    public function test_env_from_workstation_app_service_carries_its_whole_environment(): void
    {
        // utask reads its whole configuration from CFG_*; dropping it leaves
        // an app that dies on `configstore: get "callback-config"`.
        $service = [
            'build' => '.',
            'environment' => [
                'CONFIGURATION_FROM' => 'env:CFG',
                'CFG_CALLBACK_CONFIG' => '{"base_url": "http://localhost:8081", "retries": 3}',
                'CFG_LOG_LEVEL' => '${LOG_LEVEL:-info}',
                'CFG_SOURCE' => '${SOURCE}',
                'CFG_DSN' => 'postgres://u:${DB_PASS}@db/app',
                'PORT' => '8081',
                'PA_DOCROOT' => '/srv',
                'WORKERS' => 4,
            ],
        ];

        $this->assertSame([
            'CONFIGURATION_FROM' => 'env:CFG',
            'CFG_CALLBACK_CONFIG' => '{"base_url": "http://localhost:8081", "retries": 3}',
            'CFG_LOG_LEVEL' => 'info',
            'WORKERS' => '4',
        ], SidecarCredentials::envFromWorkstationAppService($service));
    }

    public function test_published_secrets_in_flags_a_literal_secret_like_key(): void
    {
        $service = ['environment' => [
            'APP_SECRET' => '94bad46abe2c1d9f',
            'LOG_LEVEL' => 'debug',
            'JWT_SECRET' => '${JWT_SECRET:?set it}',
        ]];

        // Only the literal: a reference and a non-secret key are left alone.
        $this->assertSame(['APP_SECRET'], SidecarCredentials::publishedSecretsIn($service));
    }

    public function test_published_secrets_in_is_empty_with_nothing_secret_like(): void
    {
        $service = ['environment' => ['CONFIGURATION_FROM' => 'env:CFG', 'WORKERS' => 4]];

        $this->assertSame([], SidecarCredentials::publishedSecretsIn($service));
    }

    public function test_env_from_workstation_app_service_reads_the_list_form(): void
    {
        $service = ['environment' => ['CONFIGURATION_FROM=env:CFG', 'HOST=0.0.0.0', 'PASSTHROUGH', 'CFG_A=${A:-x}']];

        $this->assertSame(
            ['CONFIGURATION_FROM' => 'env:CFG', 'CFG_A' => 'x'],
            SidecarCredentials::envFromWorkstationAppService($service)
        );
    }

    public function test_a_required_secret_is_generated_the_way_compose_placeholders_generates_it(): void
    {
        // Only credentials are invented; a required hostname is left out so
        // the app says what it needs instead of starting pointed at nothing.
        $service = ['environment' => [
            'JWT_SECRET' => '${JWT_SECRET:?JWT_SECRET must be set}',
            'API_HOST' => '${API_HOST:?set the host}',
        ]];

        $this->assertSame(
            ['JWT_SECRET' => ComposePlaceholders::generatedSecret('JWT_SECRET', 'seed')],
            SidecarCredentials::envFromWorkstationAppService($service, 'seed')
        );
        $this->assertSame([], SidecarCredentials::envFromWorkstationAppService($service));
    }

    public function test_a_sidecars_required_secret_matches_the_harvested_one(): void
    {
        $sidecar = SidecarCredentials::withRequiredSecrets(
            ['POSTGRES_PASSWORD=${DB_PASSWORD:?required}', 'POSTGRES_USER=app'],
            'seed'
        );
        $app = SidecarCredentials::envFromWorkstationAppService(
            ['environment' => ['DB_PASSWORD' => '${DB_PASSWORD:?required}']],
            'seed'
        );

        $this->assertSame($app['DB_PASSWORD'], $sidecar['POSTGRES_PASSWORD']);
        $this->assertSame('app', $sidecar['POSTGRES_USER']);
        // Nothing to settle: the environment is handed back in its own form.
        $this->assertSame(['A=1'], SidecarCredentials::withRequiredSecrets(['A=1'], 'seed'));
    }

    public function test_environment_map_normalises_list_and_mapping_forms(): void
    {
        $this->assertSame(
            ['A' => '1', 'B' => '2'],
            SidecarCredentials::environmentMap(['A=1', 'B=2'])
        );
        $this->assertSame(
            ['A' => '1', 'B' => '2'],
            SidecarCredentials::environmentMap(['A' => '1', 'B' => 2])
        );
        $this->assertSame([], SidecarCredentials::environmentMap(null));
        $this->assertSame([], SidecarCredentials::environmentMap('not-an-array'));
    }
}
