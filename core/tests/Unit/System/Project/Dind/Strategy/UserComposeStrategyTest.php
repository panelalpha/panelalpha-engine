<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The compose-strategy headline promise (ADR-0001, ticket 04): the client's
 * own `docker-compose.yml` is read and never written back to, and the file
 * the account actually runs is a hardened copy at the run-file name
 * ({@see EngineArtifacts::RUN_COMPOSE}).
 *
 * Driven through the real {@see UserComposeStrategy}, over a stubbed
 * {@see System} that answers a fixed file map and captures every
 * `filePutContents()` write the same way the staged-temp-file-then-`sudo cp`
 * write actually happens (mirroring {@see \Tests\Unit\Deploy\Dind\HostCompilePhpPlatformPinTest}).
 */
class UserComposeStrategyTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    /** @var array<string, string> target path => contents, from every `sudo cp` the write issued */
    private array $copiedTo = [];

    /** @var array<string, string> target path => mode, from every `sudo chmod` */
    private array $modes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedTo = [];
        $this->modes = [];
    }

    /**
     * @param array<string, string> $files absolute account path => contents
     */
    private function stubbedSystem(array $files): System
    {
        $copiedTo = &$this->copiedTo;
        $modes = &$this->modes;

        return new class ($files, $copiedTo, $modes) extends System {
            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo
             *  @param array<string, string> $modes */
            public function __construct(private array $files, private array &$copiedTo, private array &$modes)
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
                if (preg_match('/^sudo chmod (\S+) (\S+)$/', $line, $m) === 1) {
                    $this->modes[$m[2]] = $m[1];
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    private function stubbedDind(System $system, string $composeSourcePath, string $appDir = ''): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('publicAppUrl')->willReturn(null);
        $dind->method('userAppComposeFilePath')->willReturn(self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE);
        $dind->method('userAppExistingComposeFilePath')->willReturn($composeSourcePath);
        if ($appDir !== '') {
            $dind->method('userAppDirPath')->willReturn($appDir);
        }
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        // A DeployStrategy whose only override is secrets(): fillPlaceholders()
        // reaches it unconditionally, and the real AccountSecrets derives its
        // value from config('app.key'), which nothing in this bare PHPUnit
        // process has bootstrapped. Everything else here (ComposeHarden,
        // RubyStrategy::installHostInitializer against a directory that does
        // not exist) is real.
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
                        return 'stub-secret:' . $purpose;
                    }
                };
            }
        };
        $dind->method('strategy')->willReturn($strategy);

        return $dind;
    }

    public function test_refresh_run_file_leaves_the_clients_compose_untouched_and_writes_a_hardened_run_file(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = <<<'YAML'
        services:
          app:
            image: acme/app:latest
            ports:
              - "8080:80"
        YAML;

        $system = $this->stubbedSystem([$clientPath => $clientYaml]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $runPath = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE;
        $this->assertSame('docker-compose.panelalpha.yml', EngineArtifacts::RUN_COMPOSE);

        // The client's own file was only ever read, never written to.
        $this->assertArrayNotHasKey($clientPath, $this->copiedTo, "the client's own compose file must never be written to");

        // The run file -- a name the client cannot own (ADR-0001) -- got the
        // hardened result.
        $this->assertArrayHasKey($runPath, $this->copiedTo, 'the hardened run file was never written');
        $hardened = Yaml::parse($this->copiedTo[$runPath]);

        $this->assertSame('acme/app:latest', $hardened['services']['app']['image']);
        $this->assertSame(
            'unless-stopped',
            $hardened['services']['app']['restart'],
            'hardening must apply a restart policy the client compose file did not declare'
        );
    }

    /** deemix: `${DEEMIX_CONFIG_PATH}:/config` with nothing setting it, and compose rejected `:/config`. */
    public function test_a_mount_from_an_unset_variable_becomes_a_named_volume_in_the_run_file(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([$clientPath => "services:\n  deemix:\n    image: ghcr.io/bambanah/deemix:latest\n"
            . "    ports:\n      - 6595:6595\n    volumes:\n      - \"\${DEEMIX_CONFIG_PATH}:/config\"\n"]);

        (new UserComposeStrategy($this->stubbedDind($system, $clientPath)))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertSame(['deemix-config-path:/config'], $run['services']['deemix']['volumes']);
        $this->assertArrayHasKey('deemix-config-path', $run['volumes']);
    }

    public function test_the_clients_override_is_layered_as_a_hardened_copy_and_left_untouched(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  app:\n    image: acme/app:latest\n",
            $overridePath => "services:\n  app:\n    privileged: true\n    volumes:\n      - /var/run:/var/run\n      - ./x:/x\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $copyPath = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $this->assertArrayNotHasKey($overridePath, $this->copiedTo, "the client's own override must never be written to");
        $this->assertArrayHasKey($copyPath, $this->copiedTo);
        $this->assertSame(['volumes' => ['./x:/x']], Yaml::parse($this->copiedTo[$copyPath])['services']['app']);
    }

    public function test_mount_sources_are_checked_against_the_projects_env(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            self::PROJECT_DIR . '/.env' => "SOCK=/var/run/docker.sock\nDATA_DIR=./storage\n",
            $clientPath => "services:\n  app:\n    image: acme/app:latest\n    volumes:\n"
                . "      - \${SOCK}:/sock\n      - \${NOPE:-/etc}:/conf\n      - \${DATA_DIR:-./data}:/data\n",
            $overridePath => "services:\n  app:\n    volumes:\n      - \${SOCK}:/also-sock\n      - ./x:/x\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);
        $dind->method('userAppDirPath')->willReturn(self::PROJECT_DIR);
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $dind->method('environment')->willReturnCallback(fn (): ProjectEnvironment => new ProjectEnvironment($dind));

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertSame(['${DATA_DIR:-./data}:/data'], $run['services']['app']['volumes']);
        $override = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE]);
        $this->assertSame(['./x:/x'], $override['services']['app']['volumes']);
    }

    /** Appwrite's override sets only `command:` on the Traefik the run file just dropped. */
    public function test_the_override_loses_a_service_the_run_file_dropped(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  traefik:\n    image: traefik:3.6\n    ports:\n      - 80:80\n"
                . "  appwrite:\n    image: appwrite/appwrite:1.6\n    labels:\n      - traefik.enable=true\n",
            $overridePath => "services:\n  traefik:\n    command:\n      - --providers.docker=true\n"
                . "  appwrite:\n    environment:\n      - _APP_ENV=development\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertArrayNotHasKey('traefik', $run['services']);
        $override = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE]);
        $this->assertSame(['appwrite'], array_keys($override['services']));
    }

    /** reiverr's override builds a `development` stage over the checkout; the base file runs a published image. */
    public function test_a_workstation_override_is_not_layered(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  reiverr-frontend:\n    image: ghcr.io/aleksilassila/reiverr:latest\n    ports:\n      - 9494:9494\n",
            $overridePath => "services:\n  reiverr-frontend:\n    volumes:\n      - ./:/usr/src/app/\n"
                . "    build:\n      context: .\n      target: development\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $this->assertArrayHasKey(self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE, $this->copiedTo);
        // A copy that layers nothing: with no copy at all Paths::composeFiles()
        // falls back to the raw override, and the development build is back.
        $copy = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $this->assertArrayHasKey($copy, $this->copiedTo);
        $this->assertSame(['services' => []], Yaml::parse($this->copiedTo[$copy]));
    }

    public function test_services_the_clients_override_includes_are_hardened_in_the_copy(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  app:\n    image: acme/app:latest\n",
            self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME => "include:\n  - evil.yml\n",
            self::PROJECT_DIR . '/evil.yml' => "services:\n  x:\n    image: alpine\n    privileged: true\n"
                . "    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $copy = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE]);
        $this->assertArrayNotHasKey('include', $copy);
        $this->assertSame(['image' => 'alpine'], $copy['services']['x']);
    }

    /**
     * Damselfly: a recipe replaces the compose file, and the repository's
     * Visual Studio override names a service (damselfly.web) that no longer
     * exists. An override the recipe wrote itself is still layered.
     */
    public function test_a_replacing_app_config_compose_drops_the_repositorys_own_override_only(): void
    {
        $recipePath = self::PROJECT_DIR . '/' . EngineArtifacts::APP_CONFIG_COMPOSE;
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $copyPath = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $repoOverride = "services:\n  damselfly.web:\n    environment:\n      - ASPNETCORE_ENVIRONMENT=Development\n";
        $strategy = fn (Dind $dind, ?string $committed): UserComposeStrategy => new class ($dind, $committed) extends UserComposeStrategy {
            public function __construct(Dind $dind, private ?string $committed)
            {
                parent::__construct($dind);
            }

            protected function committedVersion(string $projectDir, string $relative): ?string
            {
                return $this->committed;
            }
        };

        $files = [$recipePath => "services:\n  damselfly:\n    image: webreaper/damselfly:4.5.3\n", $overridePath => $repoOverride];
        $strategy($this->stubbedDind($this->stubbedSystem($files), $recipePath), rtrim($repoOverride))
            ->refreshRunFile(self::PROJECT_DIR, '1001:1001');
        $this->assertArrayNotHasKey($copyPath, $this->copiedTo, "the repository's override is not layered over the recipe's file");

        $this->copiedTo = [];
        $files[$overridePath] = "services:\n  damselfly:\n    environment:\n      - PUID=1001\n";
        $strategy($this->stubbedDind($this->stubbedSystem($files), $recipePath), $repoOverride)
            ->refreshRunFile(self::PROJECT_DIR, '1001:1001');
        $this->assertArrayHasKey($copyPath, $this->copiedTo, 'an override the recipe wrote is still layered');
    }

    /**
     * The same Damselfly shape deployed as an archive: there is no git
     * history, so what the repository shipped is the override as it stood
     * before the app config ran.
     */
    public function test_without_git_the_override_from_before_the_app_config_decides(): void
    {
        $recipePath = self::PROJECT_DIR . '/' . EngineArtifacts::APP_CONFIG_COMPOSE;
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $copyPath = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $repoOverride = "version: \"3.4\"\nservices:\n  devonly.sidecar:\n    environment:\n      - X=1\n";
        $recipe = "services:\n  app:\n    image: nginx:alpine\n";
        $archive = fn (Dind $dind): UserComposeStrategy => new class ($dind) extends UserComposeStrategy {
            protected function committedVersion(string $projectDir, string $relative): ?string
            {
                return null;
            }
        };

        // The repository shipped it: noted before the app config, unchanged since.
        $strategy = $archive($this->stubbedDind($this->stubbedSystem([$recipePath => $recipe, $overridePath => $repoOverride]), $recipePath));
        $strategy->noteRepositoryOverride(self::PROJECT_DIR);
        $strategy->refreshRunFile(self::PROJECT_DIR, '1001:1001');
        $this->assertArrayNotHasKey($copyPath, $this->copiedTo, "the repository's override is not layered over the recipe's file");

        // The repository shipped none, so the one there now is the recipe's own.
        $this->copiedTo = [];
        $live = new \ArrayObject([$recipePath => $recipe]);
        $strategy = $archive($this->stubbedDind($this->liveSystem($live), $recipePath));
        $strategy->noteRepositoryOverride(self::PROJECT_DIR);
        $live[$overridePath] = "services:\n  app:\n    environment:\n      - PUID=1001\n";
        $strategy->refreshRunFile(self::PROJECT_DIR, '1001:1001');
        $this->assertArrayHasKey($copyPath, $this->copiedTo, 'an override the recipe wrote is still layered');
    }

    /** A system whose files can change between two calls, as a prepare hook changes them. */
    private function liveSystem(\ArrayObject $files): System
    {
        $copiedTo = &$this->copiedTo;

        return new class ($files, $copiedTo) extends System {
            /** @param array<string, string> $copiedTo */
            public function __construct(private \ArrayObject $files, private array &$copiedTo)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this, $this->files) extends SystemFilesystem {
                    public function __construct(System $engine, private \ArrayObject $files)
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    /**
     * Domain Watchdog disables its worker's inherited healthcheck with
     * `test: []`; written back as `test: {}` Compose refused the whole project
     * ("healthcheck.test must be a string"). Empty maps must stay maps.
     */
    public function test_refresh_run_file_keeps_empty_sequences_and_empty_maps_apart(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = <<<'YAML'
        x-worker: &worker
          image: acme/app:latest
          entrypoint: []
        services:
          app:
            image: acme/app:latest
            ports:
              - "8080:80"
            labels: {}
            networks: {}
          php-worker:
            <<: *worker
            command: php bin/console messenger:consume
            healthcheck:
              test: [ ]
              disable: true
        volumes:
          data: {}
        x-notes: {}
        YAML;

        $system = $this->stubbedSystem([$clientPath => $clientYaml]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $runPath = self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE;
        $this->assertArrayHasKey($runPath, $this->copiedTo, 'the hardened run file was never written');
        // Maps as objects, so an empty map and an empty sequence differ.
        $run = Yaml::parse($this->copiedTo[$runPath], Yaml::PARSE_OBJECT_FOR_MAP);
        $worker = $run->services->{'php-worker'};

        $this->assertSame([], $worker->healthcheck->test, 'healthcheck.test: [] must stay a sequence');
        $this->assertSame([], $worker->entrypoint, 'a merged-in entrypoint: [] must stay a sequence');
        $this->assertEquals(new \stdClass(), $run->services->app->labels, 'labels: {} must stay a map');
        $this->assertEquals(new \stdClass(), $run->services->app->networks, 'service networks: {} must stay a map');
        $this->assertEquals(new \stdClass(), $run->volumes->data, 'a named volume {} must stay a map');
        $this->assertEquals(new \stdClass(), $run->{'x-notes'}, 'a top-level {} must stay a map');
    }

    public function test_a_bind_through_a_committed_symlink_is_removed_from_the_run_file_and_the_override(): void
    {
        // The checkout as core sees it; the stubbed file map stands in for the reads.
        $root = sys_get_temp_dir() . '/ucs-link-' . bin2hex(random_bytes(4));
        $appDir = $root . '/acme/project';
        mkdir($appDir . '/keep', 0755, true);
        symlink('/var/run', $appDir . '/data');

        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  app:\n    image: acme/app:latest\n    volumes:\n      - ./data:/sock\n      - ./keep:/keep\n",
            $overridePath => "services:\n  app:\n    volumes:\n      - ./data/docker.sock:/o\n      - ./keep:/k\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath, $appDir);

        try {
            (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertSame(['./keep:/keep'], $run['services']['app']['volumes']);
        $override = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE]);
        $this->assertSame(['./keep:/k'], $override['services']['app']['volumes']);
    }

    public function test_refresh_run_file_is_a_noop_when_the_project_ships_no_compose_file(): void
    {
        $system = $this->stubbedSystem([]);
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppExistingComposeFilePath')->willReturn(null);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $this->assertSame([], $this->copiedTo, 'nothing should be written when the project ships no compose file');
    }

    /**
     * The run file sits at the root, so a stack kept under docker/ is rebased
     * onto it (engine#91): a `./data` bind is `./docker/data` from there.
     */
    public function test_a_nested_compose_keeps_its_paths_from_the_root(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker/docker-compose.yml';
        $system = $this->stubbedSystem([$clientPath => <<<'YAML'
        services:
          app:
            image: acme/app:latest
            env_file: .env
            ports:
              - "8080:80"
            volumes:
              - ./config:/etc/app:ro
              - data:/var/lib/app
        volumes:
          data:
        YAML]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $app = $run['services']['app'];
        $this->assertContains('./docker/config:/etc/app:ro', $app['volumes']);
        $this->assertContains('data:/var/lib/app', $app['volumes']);
        $this->assertContains('./docker/.env', (array) $app['env_file']);
    }

    public function test_the_accounts_own_panelalpha_tree_may_be_bound_by_absolute_path(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $overridePath = self::PROJECT_DIR . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $system = $this->stubbedSystem([
            $clientPath => "services:\n  app:\n    image: acme/app:latest\n    volumes:\n"
                . "      - /home/acme/.panelalpha/app:/data\n      - /home/acme/docker:/d\n",
            $overridePath => "services:\n  app:\n    volumes:\n      - /home/acme/.panelalpha/conf:/conf\n"
                . "      - /home/other/.panelalpha:/other\n",
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertSame(['/home/acme/.panelalpha/app:/data'], $run['services']['app']['volumes']);
        $override = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE]);
        $this->assertSame(['/home/acme/.panelalpha/conf:/conf'], $override['services']['app']['volumes']);
    }

    /**
     * onetimesecret: the root file only `include:`s its stack. The included
     * services are hardened like the file's own, with their paths from the root.
     */
    public function test_an_include_only_compose_file_is_flattened_and_hardened(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([
            $clientPath => "include:\n  - path: docker/compose/simple.yml\n",
            self::PROJECT_DIR . '/docker/compose/simple.yml' => <<<'YAML'
            services:
              app:
                image: onetimesecret/onetimesecret:latest
                container_name: onetime-app
                privileged: true
                ports:
                  - "3000:3000"
                volumes:
                  - ./data:/app/data
                  - /var/run/docker.sock:/var/run/docker.sock
                healthcheck:
                  test: []
            YAML,
        ]);
        $dind = $this->stubbedDind($system, $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $dumped = $this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE];
        $run = Yaml::parse($dumped);
        $this->assertArrayNotHasKey('include', $run);
        $app = $run['services']['app'];
        $this->assertArrayNotHasKey('privileged', $app);
        $this->assertArrayHasKey('mem_limit', $app);
        $this->assertSame(['./docker/compose/data:/app/data'], $app['volumes']);
        $this->assertStringContainsString('test: []', $dumped);
    }

    public function test_an_include_that_cannot_be_read_fails_the_deploy(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([$clientPath => "include:\n  - missing.yml\n"]);
        $dind = $this->stubbedDind($system, $clientPath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('includes missing.yml');
        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');
    }

    /** Nothing published: the port the service's PORT names is, not the 8080 guess. */
    public function test_a_service_that_publishes_nothing_is_published_on_the_port_it_names(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = "services:\n  web:\n    image: acme/app:latest\n    environment:\n      - PORT=9494\n";
        $dind = $this->stubbedDind($this->stubbedSystem([$clientPath => $clientYaml]), $clientPath);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $run = Yaml::parse($this->copiedTo[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE]);
        $this->assertSame(['9494:9494'], $run['services']['web']['ports']);
    }

    /** engine#173: the run file inlines env_vars and generated passwords. */
    public function test_the_run_file_is_written_owner_only(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([$clientPath => "services:\n  app:\n    image: acme/app\n"]);

        (new UserComposeStrategy($this->stubbedDind($system, $clientPath)))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $this->assertSame('600', $this->modes[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE] ?? null);
    }

    public function test_a_run_file_copied_through_verbatim_is_owner_only_too(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $system = $this->stubbedSystem([$clientPath => "version: '3'\n"]);

        (new UserComposeStrategy($this->stubbedDind($system, $clientPath)))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $this->assertSame('600', $this->modes[self::PROJECT_DIR . '/' . EngineArtifacts::RUN_COMPOSE] ?? null);
    }
}
