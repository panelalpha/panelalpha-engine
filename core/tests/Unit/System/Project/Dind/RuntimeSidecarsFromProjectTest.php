<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Ticket 04: a recipe repo (framework/Dockerfile/Railpack strategy, not the
 * compose strategy) that ships its own local-dev compose file -- a Postgres
 * sidecar beside an `app` service built from the repo's own root, Sail-
 * or Symfony-Docker-shaped.
 *
 * {@see RuntimeSidecars::runtimeSidecarsFromProject()} only ever *reads*
 * that file through {@see ProjectFiles::read()} to harvest its backing
 * services; nothing in this class writes anything, which is what makes the
 * three promises here (no stray stash file, the sidecar survives, the
 * client's own compose file is untouched) hold simultaneously -- proven here
 * by driving the real method rather than asserting the absence of a write
 * by reading the source.
 */
class RuntimeSidecarsFromProjectTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    /** @var array<string, string> target path => contents, from every `sudo cp` a write issued */
    private array $copiedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedTo = [];
    }

    /**
     * @param array<string, string> $files absolute account path => contents
     */
    private function stubbedSystem(array $files): System
    {
        $copiedTo = &$this->copiedTo;

        return new class ($files, $copiedTo) extends System {
            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo */
            public function __construct(private array $files, private array &$copiedTo)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class ($engine, $files) extends SystemFilesystem {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }
        };
    }

    private function stubbedDind(System $system): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        $innerDocker = $this->createStub(InnerDocker::class);
        $innerDocker->method('declaredImagePorts')->willReturn([]);
        $dind->method('innerDocker')->willReturn($innerDocker);

        // placeholderSeed() reaches strategy()->secrets()->for(...) eagerly,
        // and the real AccountSecrets derives from config('app.key'), which
        // nothing in this bare PHPUnit process has bootstrapped.
        $strategy = new class ($dind) extends DeployStrategy {
            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function userEnvVars(): array
                    {
                        return [];
                    }

                    public function for(string $purpose): string
                    {
                        return 'stub-seed:' . $purpose;
                    }
                };
            }
        };
        $dind->method('strategy')->willReturn($strategy);

        return $dind;
    }

    public function test_a_recipes_local_dev_sidecar_is_kept_with_no_stash_file_and_the_client_file_untouched(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = <<<'YAML'
        services:
          app:
            build: .
            volumes:
              - .:/var/www/html
            ports:
              - "8000:8000"
          db:
            image: postgres:16
            environment:
              POSTGRES_USER: acme
              POSTGRES_PASSWORD: secret
              POSTGRES_DB: acme
            volumes:
              - dbdata:/var/lib/postgresql/data
        volumes:
          dbdata:
        YAML;

        $system = $this->stubbedSystem([$clientPath => $clientYaml]);
        $dind = $this->stubbedDind($system);

        $result = (new RuntimeSidecars($dind))->runtimeSidecarsFromProject(self::PROJECT_DIR);

        // The sidecar is kept...
        $this->assertSame(['db'], array_keys($result['services']), 'the Postgres sidecar must survive extraction');

        // ...nothing was ever written back, so no `*.panelalpha-local` stash
        // file (the previous engine's mechanism) and no write to the client's
        // own file either -- this class only reads.
        $this->assertSame([], $this->copiedTo, 'reading a project for its sidecars must never write anything');
        $this->assertSame(
            $clientYaml,
            $system->filesystem()->fileGetContents($clientPath),
            "the client's own compose file must stay exactly what it shipped"
        );
    }

    /**
     * @param array<string, string> $files name => contents, written to a real
     *        directory because the template glob reads the disk
     * @return array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}
     */
    private function sidecarsFromFiles(array $files): array
    {
        $dir = sys_get_temp_dir() . '/pa-sidecars-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $byPath = [];
        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
            $byPath[$dir . '/' . $name] = $contents;
        }
        try {
            return (new RuntimeSidecars($this->stubbedDind($this->stubbedSystem($byPath))))->runtimeSidecarsFromProject($dir);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    /**
     * Zerobyte: every service kept from its workstation file was
     * its e2e suite. Dropping them must not drop the production variant's env
     * too, which the app used to get with them.
     */
    public function test_a_workstation_file_left_with_only_a_test_suite_still_gives_the_app_its_env(): void
    {
        $result = $this->sidecarsFromFiles([
            'compose.yaml' => (string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/compose/zerobyte-compose.yaml'),
        ]);

        $this->assertSame([], $result['services']);
        $this->assertSame('debug', $result['app_env']['LOG_LEVEL'] ?? null);
        $this->assertArrayHasKey('APP_SECRET', $result['app_env']);
        $this->assertArrayNotHasKey('NODE_ENV', $result['app_env']);

        $decision = (new RuntimeSidecars($this->stubbedDind($this->stubbedSystem([]))))
            ->mergeRuntimeSidecars(['env' => ['LOG_LEVEL' => 'info']], $result);
        $this->assertSame('info', $decision['env']['LOG_LEVEL'], 'what the strategy generates still wins');
        $this->assertArrayHasKey('APP_SECRET', $decision['env']);
        $this->assertArrayNotHasKey('depends_on', $decision);
    }

    /** LinkAce's shape, reduced: a workstation stack beside a production one. */
    private const LINKACE_DEV = <<<'YAML'
    name: linkace_dev
    services:
      db:
        image: docker.io/library/mariadb:12.0
        environment:
          - MYSQL_ROOT_PASSWORD=${DB_PASSWORD}
          - MYSQL_DATABASE=${DB_DATABASE}
      pg-db:
        image: docker.io/library/postgres:17
        environment:
          - POSTGRES_PASSWORD=${DB_PASSWORD}
      php:
        build:
          context: .
          dockerfile: ./resources/docker/dockerfiles/development.Dockerfile
        depends_on: [db]
        volumes:
          - .:/app:delegated
      caddy:
        image: docker.io/library/caddy:2
        depends_on: [php]
        volumes:
          - .:/app:delegated
      buggregator:
        image: ghcr.io/buggregator/server:latest
        ports:
          - "8000:8000"
        depends_on: [php]
    YAML;

    private const LINKACE_PRODUCTION = <<<'YAML'
    services:
      app:
        image: docker.io/linkace/linkace:latest
        depends_on: [db, redis]
      db:
        image: docker.io/library/mariadb:12.0
        environment:
          - MYSQL_ROOT_PASSWORD=${DB_PASSWORD}
          - MYSQL_DATABASE=${DB_DATABASE}
        volumes:
          - db:/var/lib/mysql
      redis:
        image: docker.io/library/redis:8.2
    volumes:
      db:
    YAML;

    /**
     * The production file says what runs beside the app; the workstation file
     * added caddy, a second database and a debug server.
     */
    public function test_a_production_compose_beats_the_development_one(): void
    {
        $result = $this->sidecarsFromFiles([
            'docker-compose.yml' => self::LINKACE_DEV,
            'docker-compose.production.yml' => self::LINKACE_PRODUCTION,
        ]);

        $this->assertSame(['db', 'redis'], array_keys($result['services']));
    }

    public function test_without_a_production_compose_the_development_one_still_supplies_the_database(): void
    {
        $result = $this->sidecarsFromFiles(['docker-compose.yml' => self::LINKACE_DEV]);

        $this->assertArrayHasKey('db', $result['services']);
        $this->assertArrayNotHasKey('buggregator', $result['services'], 'a debug server is workstation tooling');
    }

    /** Glob order put `.dev` first; the neutral file is the better description. */
    public function test_a_development_template_is_read_after_a_neutral_one(): void
    {
        $result = $this->sidecarsFromFiles([
            'docker-compose.dev.yml' => "services:\n  mysql:\n    image: mysql:8\n",
            'docker-compose.stack.yml' => "services:\n  postgres:\n    image: postgres:16\n",
        ]);

        $this->assertSame(['postgres'], array_keys($result['services']));
    }

    /**
     * Playerr from an archive: no repository URL to match, but its own
     * docker-compose.yml builds `playerr` from the root, so the casaos and
     * github variants of that service are the app, not backing services.
     */
    public function test_a_variant_of_the_root_build_is_not_a_backing_service_without_a_repository(): void
    {
        $result = $this->sidecarsFromFiles([
            'docker-compose.yml' => "services:\n  playerr:\n    build: .\n    container_name: playerr\n"
                . "    ports:\n      - \"2727:2727\"\n    volumes:\n      - ./config:/app/config\n",
            'docker-compose.casaos.yml' => "services:\n  playerr:\n    image: playerr:latest\n    container_name: playerr\n"
                . "    network_mode: bridge\n    ports:\n      - \"2727:2727\"\n",
            'docker-compose.github.yml' => "services:\n  playerr:\n    image: maikboarder/playerr:latest\n    container_name: playerr\n"
                . "    ports:\n      - \"2727:2727\"\n",
        ]);

        $this->assertSame([], $result['services']);
    }

    public function test_a_template_datastore_beside_the_root_build_is_still_kept(): void
    {
        $result = $this->sidecarsFromFiles([
            'docker-compose.yml' => "services:\n  web:\n    build:\n      context: ./\n    volumes:\n      - .:/app\n",
            'docker-compose.example.yml' => "services:\n  web:\n    image: acme/shop:1\n  cache:\n    image: redis:7\n",
        ]);

        $this->assertSame(['cache'], array_keys($result['services']));
    }

    public function test_a_development_template_still_speaks_when_it_is_the_only_one(): void
    {
        $result = $this->sidecarsFromFiles([
            'docker-compose.dev.yml' => "services:\n  mysql:\n    image: mysql:8\n",
        ]);

        $this->assertSame(['mysql'], array_keys($result['services']));
    }
}
