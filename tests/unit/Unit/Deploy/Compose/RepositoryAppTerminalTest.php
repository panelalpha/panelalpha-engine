<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RepositoryAppTerminal;
use PHPUnit\Framework\TestCase;

class RepositoryAppTerminalTest extends TestCase
{
    /** MintHCM: docker/docker-compose.yml runs the published image of docker/Dockerfile. */
    private const MINTHCM = <<<'YAML'
services:
  minthcm-web:
    image: minthcm/minthcm
    ports: ['${WEB_PORT}:80']
    depends_on:
      minthcm-db:
        condition: service_healthy
    tty: true
    stdin_open: true
  minthcm-db:
    image: percona/percona-server:8.0
  minthcm-es:
    image: docker.elastic.co/elasticsearch/elasticsearch:7.16.3
YAML;

    public function test_takes_the_settings_of_the_files_application_service(): void
    {
        $settings = RepositoryAppTerminal::settings('docker/Dockerfile', [
            'docker-compose.yml' => null,
            'docker/docker-compose.yml' => self::MINTHCM,
        ], 'minthcm/minthcm');

        $this->assertSame(['tty' => true, 'stdin_open' => true], $settings);
    }

    public function test_the_service_building_the_dockerfile_wins(): void
    {
        $yaml = <<<'YAML'
services:
  worker:
    build: { context: .., dockerfile: docker/worker.Dockerfile }
    tty: true
  web:
    build: { context: .., dockerfile: docker/Dockerfile }
    stdin_open: true
    depends_on: [worker]
YAML;

        $this->assertSame(['stdin_open' => true], RepositoryAppTerminal::settings('docker/Dockerfile', ['docker/compose.yml' => $yaml]));
    }

    public function test_a_root_build_matches_the_root_dockerfile(): void
    {
        $yaml = "services:\n  app:\n    build: .\n    tty: 'true'\n";

        $this->assertSame(['tty' => true], RepositoryAppTerminal::settings('Dockerfile', ['docker-compose.yml' => $yaml]));
    }

    public function test_nothing_without_a_compose_or_a_terminal(): void
    {
        $this->assertSame([], RepositoryAppTerminal::settings('Dockerfile', ['docker-compose.yml' => null]));
        $this->assertSame([], RepositoryAppTerminal::settings('Dockerfile', ['docker-compose.yml' => "services:\n  app:\n    build: .\n"]));
        $this->assertSame([], RepositoryAppTerminal::settings('Dockerfile', ['docker-compose.yml' => "services:\n  app:\n    build: .\n    tty: false\n"]));
    }

    /** Two candidates for the application: which one is ours is a guess, so none is taken. */
    public function test_an_ambiguous_file_gives_nothing(): void
    {
        $yaml = <<<'YAML'
services:
  a: { image: x/a, tty: true, depends_on: [db] }
  b: { image: x/b, tty: true, depends_on: [db] }
  db: { image: postgres:16 }
YAML;

        $this->assertSame([], RepositoryAppTerminal::settings('Dockerfile', ['docker-compose.yml' => $yaml]));
    }
}
