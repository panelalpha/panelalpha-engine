<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Probes\ComposeUsableProbe;

/**
 * Is this compose file the stack to deploy, or something else that happens
 * to be written in compose?
 *
 * The compose manifest sits at the top of the detection order, so a false
 * match here is not a wrong build but no build at all: the engine runs a
 * developer's local-dev stack, or a file it generated itself on the last
 * deploy, in place of the project.
 */
class ComposeUsableProbeTest extends ProbeTestCase
{
    private function probe(): ComposeUsableProbe
    {
        return new ComposeUsableProbe();
    }

    private const APP_STACK = <<<'YAML'
    services:
      app:
        image: ghcr.io/acme/app:latest
        ports:
          - "8080:8080"
      db:
        image: postgres:16
    YAML;

    public function test_a_shipped_stack_is_the_one_to_deploy(): void
    {
        $this->write('docker-compose.yml', self::APP_STACK);

        $this->assertSame(
            ['compose_path' => $this->dir . '/docker-compose.yml'],
            $this->probe()->evaluate($this->context())
        );
    }

    public function test_the_modern_filename_is_preferred(): void
    {
        // Repos mid-rename carry both. compose.yaml is the current spelling
        // and the one Docker itself prefers.
        $this->write('compose.yaml', self::APP_STACK);
        $this->write('docker-compose.yml', self::APP_STACK);

        $this->assertSame(
            $this->dir . '/compose.yaml',
            $this->probe()->evaluate($this->context())['compose_path']
        );
    }

    public function test_a_compose_file_the_engine_generated_is_skipped(): void
    {
        // Redeploying an account that already deployed once. Treating our own
        // output as the project's stack freezes the app at whatever the last
        // run produced, ignoring the sources that just arrived.
        $this->write('docker-compose.yml', <<<YAML
        services:
          app:
            image: nginx:alpine
            labels:
              {$this->labelLine()}
        YAML);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_compose_file_referencing_our_own_dockerfile_is_skipped(): void
    {
        $this->write('docker-compose.yml', <<<YAML
        services:
          app:
            build:
              dockerfile: {$this->generatedDockerfile()}
        YAML);
        $this->write(DockerfileBuilder::FILENAME, "FROM php:8.3-cli\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_generated_static_stack_is_recognised_without_a_label(): void
    {
        // The shape the static platform emits, from before the label existed.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            image: nginx:alpine
            volumes:
              - ./:/usr/share/nginx/html:ro
        YAML);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_clients_static_nginx_stack_is_the_one_to_deploy(): void
    {
        // Same image as the bootstrap, but serving a directory the client
        // chose. Skipping it served the placeholder instead of their site.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx:alpine
            ports:
              - "8080:80"
            volumes:
              - ./html:/usr/share/nginx/html:ro
        YAML);

        $this->assertSame(
            ['compose_path' => $this->dir . '/docker-compose.yml'],
            $this->probe()->evaluate($this->context())
        );
    }

    public function test_a_local_dev_stack_that_bind_mounts_the_source_is_skipped(): void
    {
        // Mounting the checkout over the image means the container serves
        // whatever is on disk. That is how you work on an app, not how you
        // deploy one.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            build: .
            volumes:
              - .:/var/www/html
        YAML);
        $this->write('Dockerfile', "FROM php:8.3-cli\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_stack_needing_the_developers_uid_is_skipped(): void
    {
        // Sail. Nothing on a tenant host supplies WWWUSER.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          laravel.test:
            build:
              context: ./vendor/laravel/sail/runtimes/8.3
              args:
                WWWGROUP: '${WWWGROUP}'
        YAML);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_compose_file_of_databases_alone_is_skipped(): void
    {
        // Common in repos that run the app on the host and only containerise
        // its backing services. There is no app service to start.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          postgres:
            image: postgres:16
          redis:
            image: redis:7
        YAML);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_stack_whose_dockerfile_is_absent_is_skipped(): void
    {
        // The build would fail on the first line. Better to fall through to a
        // platform that can construct one.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            build:
              context: .
              dockerfile: docker/prod.Dockerfile
        YAML);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_missing_reference_is_forgiven_when_a_root_dockerfile_exists(): void
    {
        // The reference is stale, but there is something buildable here and
        // compose defaults to it.
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            build:
              context: .
              dockerfile: docker/prod.Dockerfile
        YAML);
        $this->write('Dockerfile', "FROM node:20\n");

        $this->assertSame(
            $this->dir . '/docker-compose.yml',
            $this->probe()->evaluate($this->context())['compose_path']
        );
    }

    public function test_a_stack_whose_dockerfile_is_present_is_usable(): void
    {
        $this->write('docker-compose.yml', <<<'YAML'
        services:
          app:
            build:
              context: .
              dockerfile: docker/prod.Dockerfile
        YAML);
        $this->write('docker/prod.Dockerfile', "FROM node:20\n");

        $this->assertSame(
            $this->dir . '/docker-compose.yml',
            $this->probe()->evaluate($this->context())['compose_path']
        );
    }

    public function test_a_project_with_no_compose_file_is_no_match(): void
    {
        $this->write('package.json', '{}');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /**
     * ADR-0001: an app config's `replace`-mode compose is written under its
     * own reserved name, ahead of the repository's — {@see
     * \App\System\Project\Dind\Strategy\AppConfigBootstrap} writes it before
     * detection runs, expecting detection to pick it up here.
     */
    public function test_an_app_configs_reserved_compose_wins_over_the_repositorys_own(): void
    {
        $this->write('docker-compose.yml', self::APP_STACK);
        $this->write(EngineArtifacts::APP_CONFIG_COMPOSE, self::APP_STACK);

        $this->assertSame(
            $this->dir . '/' . EngineArtifacts::APP_CONFIG_COMPOSE,
            $this->probe()->evaluate($this->context())['compose_path']
        );
    }

    public function test_a_directory_named_like_a_compose_file_is_not_one(): void
    {
        mkdir($this->dir . '/compose.yaml');
        $this->write('docker-compose.yml', self::APP_STACK);

        $this->assertSame(
            $this->dir . '/docker-compose.yml',
            $this->probe()->evaluate($this->context())['compose_path']
        );
    }

    private function labelLine(): string
    {
        return GeneratedCompose::LABEL . ': "1"';
    }

    private function generatedDockerfile(): string
    {
        return DockerfileBuilder::FILENAME;
    }
}
