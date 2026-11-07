<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\System\Project\Dind\Strategy\UserComposeStrategy;
use Tests\TestCase;

/**
 * Pins the run file, the override copy and the log that {@see UserComposeStrategy} writes for a
 * compose file that needs every adjustment at once, and the refusals for a missing Dockerfile.
 */
class UserComposeNormalizeTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_a_compose_file_that_needs_every_adjustment(): void
    {
        $this->putFile('Dockerfile', "FROM php:8.3-apache\nCOPY . /var/www/html\n");
        $this->putFile('.env.panelalpha', "APP_DEBUG=0\n");
        $this->putFile('docker-compose.yml', <<<'YAML'
            services:
              traefik:
                image: traefik:v3
                ports:
                  - "80:80"
              app:
                build:
                  context: .
                  dockerfile: Dockerfile.prod
                ports:
                  - "8080:80"
                ulimits:
                  memlock: -1
                healthcheck:
                  test: ["CMD", "curl", "-f", "http://localhost"]
                env_file: .env
                volumes:
                  - ${DATA_DIR}:/data
                networks:
                  - proxy
                depends_on:
                  setup:
                    condition: service_completed_successfully
              setup:
                image: busybox
                command: ["sh", "-c", "mkdir -p /data/cache"]
              migrate:
                extends:
                  service: app
                command: php artisan migrate --force
                depends_on: []
                env_file: []
            networks:
              proxy:
                external: true
            YAML);
        $this->putFile('docker-compose.override.yml', <<<'YAML'
            services:
              traefik:
                environment:
                  X: "1"
              app:
                privileged: true
                environment:
                  APP_ENV: production
            YAML);

        $this->refresh();

        $this->assertMatchesGolden('user-compose-every-adjustment');
    }

    public function test_a_missing_dockerfile_without_a_root_one_fails_the_deploy(): void
    {
        $this->putFile('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: Dockerfile.prod\n");

        $this->refresh();

        $this->assertMatchesGolden('user-compose-missing-dockerfile');
    }

    public function test_a_missing_dockerfile_in_another_context_fails_even_with_a_root_one(): void
    {
        $this->putFile('Dockerfile', "FROM nginx\n");
        $this->putFile('docker-compose.yml', "services:\n  app:\n    build: ./api\n");
        $this->putFile('api/.keep', '');

        $this->refresh();

        $this->assertMatchesGolden('user-compose-missing-context-dockerfile');
    }

    public function test_a_file_with_no_services_is_copied_through_and_its_override_removed(): void
    {
        $this->putFile('docker-compose.yml', "x-anchors: &a\n  image: nginx\n");
        $this->putFile('docker-compose.panelalpha.client-override.yml', "services: {}\n");

        $this->refresh();

        $this->assertMatchesGolden('user-compose-no-services');
    }

    private function refresh(): void
    {
        $source = $this->dir.'/docker-compose.yml';
        try {
            (new UserComposeStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001, 'memory_limit' => 1024], null, null, null, null, 'https://acme.example.test', $source)))
                ->refreshRunFile($this->dir, '1001:1001');
        } catch (\Throwable $e) {
            $this->record('threw '.$e::class, $e->getMessage());
        }
    }
}
