<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Apache Guacamole's shape: its own Dockerfile runs `COPY .`
 * and then the RAT license check over everything it copied. The engine's run
 * file and an empty `.env` it created were in that context and failed it.
 */
class DockerfileBuildContextTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    /** @var array<string, string> */
    private array $written = [];

    /**
     * @param array<string, string> $files name relative to the project => contents
     */
    private function keep(array $files, string $dockerfile = 'Dockerfile'): void
    {
        $byPath = [];
        foreach ($files as $name => $contents) {
            $byPath[self::PROJECT_DIR . '/' . $name] = $contents;
        }
        $written = &$this->written;
        $system = new class ($byPath, $written) extends System {
            /** @param array<string, string> $files
             *  @param array<string, string> $written */
            public function __construct(private array $files, private array &$written)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;

                return new class ($this, $files) extends SystemFilesystem {
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
                    $this->written[$m[2]] = (string) file_get_contents($m[1]);
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

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $strategy = new DeployStrategy($dind);
        $dind->method('strategy')->willReturn($strategy);

        (new DockerfileStrategy($dind))->keepEngineFilesOutOfContext(['dockerfile' => $dockerfile], self::PROJECT_DIR, '1001:1001');
    }

    private function ignoreFile(string $dockerfile = 'Dockerfile'): string
    {
        $path = self::PROJECT_DIR . '/' . BuildContextIgnore::pathFor($dockerfile);
        $this->assertArrayHasKey($path, $this->written, 'no build-context ignore file was written');

        return $this->written[$path];
    }

    public function test_guacamoles_context_loses_the_run_file_and_the_empty_env(): void
    {
        $this->keep([
            'Dockerfile' => "FROM maven:3-eclipse-temurin-21 AS builder\nCOPY . \"\$BUILD_DIR\"\n",
            '.dockerignore' => ".git\ntarget/\n",
            '.env' => '',
            'docker-compose.panelalpha.yml' => "services: {}\n",
        ]);

        $lines = explode("\n", $this->ignoreFile());
        $this->assertContains('.git', $lines, "the project's own rules still apply");
        $this->assertContains('target/', $lines);
        $this->assertContains('docker-compose.panelalpha.yml', $lines);
        $this->assertContains('.env', $lines);
        $this->assertContains('Dockerfile.dockerignore', $lines);
        $this->assertArrayNotHasKey(self::PROJECT_DIR . '/.dockerignore', $this->written, "the project's .dockerignore is never edited");
    }

    public function test_an_env_with_the_accounts_values_still_reaches_the_build(): void
    {
        $this->keep(['Dockerfile' => "FROM node\nCOPY . .\nRUN npm run build\n", '.env' => "VITE_API=https://api.example.com\n"]);

        $this->assertNotContains('.env', explode("\n", $this->ignoreFile()));
    }

    public function test_a_dockerfile_specific_ignore_the_repository_ships_is_left_alone(): void
    {
        $this->keep(['Dockerfile' => 'FROM alpine', 'Dockerfile.dockerignore' => "*\n!src\n"]);

        $this->assertSame([], $this->written);
    }

    public function test_the_engines_own_file_is_rewritten_on_the_next_deploy(): void
    {
        $this->keep([
            'docker/Dockerfile' => 'FROM alpine',
            'docker/Dockerfile.dockerignore' => BuildContextIgnore::render(null, 'docker/Dockerfile', true, false),
            '.env' => '',
        ], 'docker/Dockerfile');

        $this->assertContains('.env', explode("\n", $this->ignoreFile('docker/Dockerfile')));
    }
}
