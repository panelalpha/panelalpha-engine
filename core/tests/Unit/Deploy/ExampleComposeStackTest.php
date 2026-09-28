<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ExampleComposeStackTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** we-promise/sure ships its whole stack as compose.example.yml. */
    public function test_keeps_only_the_backing_services_of_an_example_stack(): void
    {
        $path = $this->compose(<<<'YAML'
services:
  web:
    image: ghcr.io/we-promise/sure:stable
    environment:
      SELF_HOSTED: "true"
  worker:
    image: ghcr.io/we-promise/sure:stable
  db:
    image: postgres:16
    environment:
      POSTGRES_USER: sure_user
      POSTGRES_PASSWORD: sure_password
      POSTGRES_DB: sure_production
    volumes:
      - postgres-data:/var/lib/postgresql/data
  backup:
    image: prodrigestivill/postgres-backup-local
  redis:
    image: redis:latest
volumes:
  postgres-data:
YAML);

        $extracted = ComposeHarden::extractRuntimeSidecars($path, true);
        $fromYaml = ComposeHarden::extractRuntimeSidecarsFromYaml((string) file_get_contents($path), true);

        // web and worker are the application — they share an image, which is
        // one app started two ways — and we build that from the repo.
        $kept = array_keys($extracted['services']);
        $this->assertNotContains('web', $kept);
        $this->assertNotContains('worker', $kept);

        // Everything else the author shipped is started, including the backup
        // job. Dropping services we cannot identify is what used to deploy a
        // project without its database; an extra container is the cheaper
        // mistake of the two.
        $this->assertSame(['db', 'backup', 'redis'], $kept);
        $this->assertSame($extracted, $fromYaml);
        $this->assertArrayHasKey('postgres-data', $extracted['volumes']);
        $this->assertSame('redis://redis:6379/0', $extracted['env']['REDIS_URL']);
        $this->assertSame('true', $extracted['env']['SELF_HOSTED']);
    }

    /**
     * A backup job is not a database, however its image is spelled.
     *
     * It is still started — the author put it in the stack — but the
     * application's connection details must point at the database, never at
     * the job that copies it.
     */
    public function test_a_postgres_backup_sidecar_is_not_mistaken_for_the_database(): void
    {
        $path = $this->compose(<<<'YAML'
services:
  web:
    image: ghcr.io/we-promise/sure:stable
    depends_on: [db]
  db:
    image: postgres:16
    environment:
      POSTGRES_USER: real_user
      POSTGRES_PASSWORD: real_password
      POSTGRES_DB: real_db
  backup:
    image: prodrigestivill/postgres-backup-local
    environment:
      POSTGRES_HOST: db
      POSTGRES_USER: real_user
      POSTGRES_PASSWORD: real_password
YAML);

        $extracted = ComposeHarden::extractRuntimeSidecars($path, true);

        $this->assertNotContains('web', array_keys($extracted['services']), 'the app is built from the repo');
        $this->assertSame(
            'postgres://real_user:real_password@db:5432/real_db',
            $extracted['env']['DATABASE_URL'],
            'the connection must address the database, not the backup job'
        );
        $this->assertSame('db', $extracted['env']['DB_HOST']);
    }

    /**
     * Only a recognised datastore may have its port filtered out — mistaking
     * the application for one leaves the site with no port to serve on.
     */
    public function test_only_a_recognised_datastore_counts_as_one(): void
    {
        $this->assertTrue(SidecarEngine::isKnownDatastore('db', ['image' => 'postgres:16']));
        $this->assertTrue(SidecarEngine::isKnownDatastore('cache', ['image' => 'redis:latest']));
        $this->assertFalse(
            SidecarEngine::isKnownDatastore('backup', ['image' => 'prodrigestivill/postgres-backup-local'])
        );
        $this->assertFalse(
            SidecarEngine::isKnownDatastore('web', ['image' => 'ghcr.io/we-promise/sure:stable'])
        );
    }

    public function test_emits_connection_details_every_common_stack_understands(): void
    {
        $path = $this->compose(<<<'YAML'
services:
  db:
    image: postgres:16
    environment:
      POSTGRES_USER: sure_user
      POSTGRES_PASSWORD: sure_password
      POSTGRES_DB: sure_production
YAML);

        $env = ComposeHarden::extractRuntimeSidecars($path, true)['env'];

        $this->assertSame('postgres://sure_user:sure_password@db:5432/sure_production', $env['DATABASE_URL']);
        $this->assertSame('db', $env['DB_HOST']);         // Laravel
        $this->assertSame('db', $env['POSTGRES_HOST']);   // postgres-native
        $this->assertSame('sure_production', $env['POSTGRES_DB']);
    }

    public function test_the_first_database_wins_rather_than_the_last(): void
    {
        $path = $this->compose(<<<'YAML'
services:
  db:
    image: postgres:16
    environment:
      POSTGRES_DB: primary
  analytics:
    image: postgres:16
    environment:
      POSTGRES_DB: secondary
YAML);

        $env = ComposeHarden::extractRuntimeSidecars($path, true)['env'];

        $this->assertSame('db', $env['DB_HOST']);
        $this->assertStringContainsString('@db:5432/primary', $env['DATABASE_URL']);
    }

    public function test_a_dockerfile_deploy_can_carry_a_whole_stack(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 3000, [
            'env' => ['SECRET_KEY_BASE' => 'abc', 'DB_HOST' => 'db'],
            'sidecars' => ['db' => ['image' => 'postgres:16']],
            'volumes' => ['postgres-data' => null],
            'depends_on' => ['db'],
        ]);
        $compose = Yaml::parse($yaml);

        $this->assertSame(['app', 'db'], array_keys($compose['services']));
        $this->assertSame(['db' => ['condition' => 'service_started']], $compose['services']['app']['depends_on']);
        $this->assertSame('db', $compose['services']['app']['environment']['DB_HOST']);
        $this->assertArrayHasKey('postgres-data', $compose['volumes']);
    }

    public function test_compose_interpolation_defaults_become_real_credentials(): void
    {
        $path = $this->compose(<<<'YAML'
x-db-env: &db_env
  POSTGRES_USER: ${POSTGRES_USER:-sure_user}
  POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-sure_password}
  POSTGRES_DB: ${POSTGRES_DB:-sure_production}
services:
  db:
    image: postgres:16
    environment:
      <<: *db_env
  redis:
    image: redis:latest
YAML);

        $extracted = ComposeHarden::extractRuntimeSidecars($path, true);

        $this->assertSame(['db', 'redis'], array_keys($extracted['services']));
        $this->assertSame('sure_user', $extracted['services']['db']['environment']['POSTGRES_USER']);
        $this->assertArrayNotHasKey('MYSQL_DATABASE', $extracted['services']['db']['environment']);
        $this->assertSame(
            'postgres://sure_user:sure_password@db:5432/sure_production',
            $extracted['env']['DATABASE_URL']
        );
    }

    public function test_example_filenames_cover_the_common_conventions(): void
    {
        foreach (['compose.example.yml', 'docker-compose.example.yml', 'docker-compose.yml.dist', 'docker-compose.mysql.yml'] as $name) {
            $this->assertContains($name, ComposeFileInspector::COMPOSE_EXAMPLE_CANDIDATES);
        }
        // An example file must never be treated as a live compose file.
        foreach (ComposeFileInspector::COMPOSE_EXAMPLE_CANDIDATES as $name) {
            $this->assertNotContains($name, ComposeFileInspector::COMPOSE_FILE_CANDIDATES);
        }
    }

    private function compose(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/pa-example-' . bin2hex(random_bytes(6)) . '.yml';
        file_put_contents($path, $yaml);
        $this->files[] = $path;

        return $path;
    }
}
