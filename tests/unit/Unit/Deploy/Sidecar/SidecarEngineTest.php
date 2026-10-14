<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a compose service is recognised as a datastore, and what settings the
 * application is then handed.
 *
 * The cases here are the ones that were getting it wrong: an image pinned by
 * digest used to produce an unrecognisable family, and the service name "db"
 * then decided it was MySQL — so a pinned Postgres reached the app as a MySQL
 * on port 3306 with invented credentials.
 */
class SidecarEngineTest extends TestCase
{
    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function envFor(string $name, string $image, array $environment = []): array
    {
        $yaml = "services:\n"
            . "  app:\n    image: myorg/app:1\n"
            . "  {$name}:\n    image: '{$image}'\n";
        if ($environment !== []) {
            $yaml .= "    environment:\n";
            foreach ($environment as $key => $value) {
                $yaml .= "      {$key}: '{$value}'\n";
            }
        }

        return ComposeHarden::extractRuntimeSidecarsFromYaml($yaml, true)['env'];
    }

    public function test_a_digest_pinned_postgres_is_recognised_as_postgres(): void
    {
        $env = $this->envFor('db', 'postgres@sha256:1111222233334444', [
            'POSTGRES_DB' => 'appdb',
            'POSTGRES_USER' => 'appuser',
            'POSTGRES_PASSWORD' => 's3cret',
        ]);

        $this->assertSame('pgsql', $env['DB_CONNECTION']);
        $this->assertSame('5432', $env['DB_PORT']);
        $this->assertSame('appdb', $env['DB_DATABASE']);
        $this->assertSame('appuser', $env['DB_USERNAME']);
        $this->assertSame('s3cret', $env['DB_PASSWORD']);
        $this->assertSame('postgres://appuser:s3cret@db:5432/appdb', $env['DATABASE_URL']);
    }

    public function test_the_image_outvotes_a_service_called_db(): void
    {
        $env = $this->envFor('db', 'postgres:16', ['POSTGRES_USER' => 'appuser']);

        $this->assertSame('pgsql', $env['DB_CONNECTION'], 'the name "db" must not make a Postgres into a MySQL');
    }

    /**
     * A service that builds from the repository is the application, whatever
     * it is called, so the sidecar extraction drops it before classification.
     * The name-only fallback exists for direct callers, which is why both
     * halves are pinned here.
     */
    public function test_a_service_that_builds_from_the_repo_is_never_a_sidecar(): void
    {
        $yaml = "services:\n  app:\n    image: myorg/app:1\n    depends_on: [db]\n  db:\n    build: ./db\n";
        $result = ComposeHarden::extractRuntimeSidecarsFromYaml($yaml, true);

        $this->assertSame([], array_keys($result['services']));
        $this->assertArrayNotHasKey('DB_CONNECTION', $result['env']);
    }

    public function test_a_registry_with_a_port_does_not_confuse_the_image_name(): void
    {
        $env = $this->envFor('database', 'registry.internal:5000/postgres:16');

        $this->assertSame('pgsql', $env['DB_CONNECTION']);
    }

    #[DataProvider('backingImages')]
    public function test_datastores_are_recognised_by_their_image(string $image, bool $expected): void
    {
        $this->assertSame($expected, SidecarEngine::isKnownDatastore('svc', ['image' => $image]), $image);
    }

    /** @return array<string, array{string, bool}> */
    public static function backingImages(): array
    {
        return [
            'plain tag' => ['postgres:16', true],
            'digest' => ['postgres@sha256:abcdef0123456789', true],
            'registry and digest' => ['docker.io/library/redis@sha256:deadbeef', true],
            'registry with port' => ['registry.internal:5000/mariadb:11', true],
            'bitnami variant' => ['bitnami/postgresql:16', true],
            'redis stack' => ['redis/redis-stack-server:latest', true],
            'mysql server' => ['mysql/mysql-server:8.0', true],
            'valkey' => ['valkey/valkey:8', true],
            'object store' => ['minio/minio:latest', true],
            'application' => ['ghcr.io/immich-app/immich-server:release', false],
            'postgres backup job' => ['prodrigestivill/postgres-backup-local:16', false],
            'mysql backup job' => ['databack/mysql-backup:latest', false],
            'no image' => ['', false],
        ];
    }

    public function test_a_backup_job_is_not_treated_as_the_database(): void
    {
        $yaml = "services:\n"
            . "  app:\n    image: myorg/app:1\n    depends_on: [db]\n"
            . "  db:\n    image: 'postgres:16'\n"
            . "    environment:\n      POSTGRES_PASSWORD: 'real'\n"
            . "  backup:\n    image: 'prodrigestivill/postgres-backup-local:16'\n"
            . "    environment:\n      POSTGRES_HOST: db\n      POSTGRES_PASSWORD: 'wrong'\n";
        $result = ComposeHarden::extractRuntimeSidecarsFromYaml($yaml, true);

        $this->assertNotContains('app', array_keys($result['services']));
        $this->assertSame('real', $result['env']['DB_PASSWORD'], 'the database, not the job that copies it');
        $this->assertStringContainsString('@db:5432/', $result['env']['DATABASE_URL']);
    }

    public function test_a_percona_mongo_is_mongo_and_not_mysql(): void
    {
        $env = $this->envFor('store', 'percona/percona-server-mongodb:7');

        $this->assertArrayNotHasKey('DB_CONNECTION', $env);
        $this->assertSame('store', $env['MONGO_HOST']);
    }

    public function test_redis_settings_do_not_depend_on_the_service_being_called_redis(): void
    {
        $env = $this->envFor('kv', 'valkey/valkey:8');

        $this->assertSame('kv', $env['REDIS_HOST']);
        $this->assertSame('redis://kv:6379/0', $env['REDIS_URL']);
    }

    public function test_a_mysql_sidecar_gets_a_database_url_too(): void
    {
        $env = $this->envFor('mysql', 'mariadb:11', [
            'MYSQL_DATABASE' => 'shop',
            'MYSQL_USER' => 'shopuser',
            'MYSQL_PASSWORD' => 'p@ss word',
        ]);

        $this->assertSame('mysql', $env['DB_CONNECTION']);
        $this->assertSame('3306', $env['DB_PORT']);
        $this->assertSame('mysql://shopuser:p%40ss%20word@mysql:3306/shop', $env['DATABASE_URL']);
    }

    /**
     * The catalogue is loaded by a __DIR__-relative path, so moving this class
     * between directories silently emptied it — and an empty catalogue means
     * Postgres stops being a datastore, a sidecars-only compose file reads as
     * an application, and the deploy drops the database without a word.
     */
    public function test_the_shipped_dialect_catalogue_is_actually_readable(): void
    {
        $dialects = SidecarEngine::dialects();

        $this->assertNotEmpty($dialects, 'the shipped dialect catalogue failed to load');
        foreach (['postgres', 'mysql', 'redis'] as $engine) {
            $this->assertArrayHasKey($engine, $dialects);
        }
        $this->assertTrue(SidecarEngine::isKnownDatastore('db', ['image' => 'postgres:16-alpine']));
    }
}
