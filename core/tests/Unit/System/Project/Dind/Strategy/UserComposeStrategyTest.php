<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    private function stubbedDind(System $system, string $composeSourcePath): Dind
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
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

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

    public function test_refresh_run_file_is_a_noop_when_the_project_ships_no_compose_file(): void
    {
        $system = $this->stubbedSystem([]);
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppExistingComposeFilePath')->willReturn(null);

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        $this->assertSame([], $this->copiedTo, 'nothing should be written when the project ships no compose file');
    }
}
