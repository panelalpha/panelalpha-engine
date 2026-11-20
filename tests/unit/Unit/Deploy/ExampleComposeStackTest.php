<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ExampleComposeStackFixtures;

class ExampleComposeStackTest extends TestCase
{
    use ExampleComposeStackFixtures;

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
}
