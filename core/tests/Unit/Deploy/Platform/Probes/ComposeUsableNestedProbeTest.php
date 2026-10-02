<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\ComposeUsableNestedProbe;

/**
 * A compose stack kept under docker/ or deploy/ is found, but only for a
 * project nothing else claims (engine#91).
 */
class ComposeUsableNestedProbeTest extends ProbeTestCase
{
    /** WikiDocs: docker/compose.yml builds the repository from its parent. */
    private const WIKIDOCS = <<<'YAML'
    services:
      wikidocs:
        build:
          context: ..
          dockerfile: docker/dockerfile
        ports:
          - "3600:3210"
    YAML;

    /** zoraxy: a published image, placeholder host paths. */
    private const ZORAXY = <<<'YAML'
    services:
      zoraxy:
        image: zoraxydocker/zoraxy:latest
        ports:
          - 80:80
          - 8000:8000
        volumes:
          - /path/to/zoraxy/config/:/opt/zoraxy/config/
    YAML;

    private function evaluate(): array|bool
    {
        return (new ComposeUsableNestedProbe())->evaluate($this->context());
    }

    public function test_a_stack_under_docker_that_builds_the_repository_is_found(): void
    {
        $this->write('docker/compose.yml', self::WIKIDOCS);
        $this->write('docker/dockerfile', 'FROM alpine');
        $this->write('README.md', '# wiki');

        $this->assertSame(['compose_path' => $this->dir . '/docker/compose.yml'], $this->evaluate());
    }

    public function test_an_image_stack_under_deploy_is_found(): void
    {
        $this->write('deploy/docker-compose.yml', self::ZORAXY);

        $this->assertSame(['compose_path' => $this->dir . '/deploy/docker-compose.yml'], $this->evaluate());
    }

    public function test_a_directory_outside_the_list_is_not_searched(): void
    {
        // huly's ws-tests/, a docs example, a dev container: all valid compose.
        $this->write('examples/docker-compose.yml', self::ZORAXY);
        $this->write('.devcontainer/docker-compose.yml', self::ZORAXY);

        $this->assertFalse($this->evaluate());
    }

    /** autobase keeps its deployable stack in console/, beside automation/ and images/. */
    public function test_the_only_other_directory_with_a_usable_stack_is_found(): void
    {
        $this->write('console/docker-compose.yml', self::ZORAXY);
        $this->write('ws-tests/docker-compose.yml', self::ZORAXY);
        $this->write('automation/README.md', '# ansible');

        $this->assertSame(['compose_path' => $this->dir . '/console/docker-compose.yml'], $this->evaluate());
    }

    public function test_two_other_directories_with_a_stack_are_a_guess(): void
    {
        $this->write('server/docker-compose.yml', self::ZORAXY);
        $this->write('agent/docker-compose.yml', self::ZORAXY);

        $this->assertFalse($this->evaluate());
    }

    public function test_a_project_railpack_recognises_is_left_to_railpack(): void
    {
        $this->write('docker/docker-compose.yml', self::ZORAXY);
        $this->writeJson('package.json', ['name' => 'x', 'scripts' => ['start' => 'node index.js']]);

        $this->assertFalse($this->evaluate());
    }

    public function test_a_workstation_stack_binding_the_repository_is_skipped(): void
    {
        // `..` from docker/ is the checkout itself.
        $this->write('docker/docker-compose.yml', <<<'YAML'
        services:
          app:
            build: ..
            volumes:
              - ..:/app
        YAML);
        $this->write('Dockerfile', 'FROM alpine');

        $this->assertFalse($this->evaluate());
    }

    public function test_a_datastores_only_stack_is_skipped(): void
    {
        $this->write('docker/docker-compose.yml', "services:\n  db:\n    image: postgres:16\n");

        $this->assertFalse($this->evaluate());
    }

    public function test_a_stack_building_a_dockerfile_that_is_not_there_is_skipped(): void
    {
        $this->write('docker/compose.yml', self::WIKIDOCS);

        $this->assertFalse($this->evaluate());
    }

    public function test_detection_takes_it_only_where_it_used_to_give_up(): void
    {
        $this->write('docker/compose.yml', self::WIKIDOCS);
        $this->write('docker/dockerfile', 'FROM alpine');
        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('compose', $decision['strategy']);
        $this->assertSame($this->dir . '/docker/compose.yml', $decision['compose_path']);

        // A root index.html is a static site today, and stays one.
        $this->write('index.html', '<html><body>hi</body></html>');
        $this->assertSame('static', DetectProjectStrategy::detect($this->dir)['strategy']);
    }
}
