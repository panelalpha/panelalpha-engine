<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\Lib\Deploy\Platform\Strategies;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Bugzilla's shape: a compose `build:` whose Dockerfile copies its context
 * into the docroot served `.git/config` to anyone.
 */
class UserComposeBuildContextTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    /** @var array<string, string> */
    private array $written = [];

    /**
     * @param array<string, string> $files name relative to the project => contents
     */
    private function keep(array $files): void
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

        $dind->method('userAppComposeFilePath')->willReturn(self::PROJECT_DIR . '/docker-compose.panelalpha.yml');

        $strategy->keepEngineFilesOutOfBuildContext(['strategy' => Strategies::COMPOSE], self::PROJECT_DIR, '1001:1001');
    }

    private function ignoreFile(string $dockerfile = 'Dockerfile'): string
    {
        $path = self::PROJECT_DIR . '/' . BuildContextIgnore::pathFor($dockerfile);
        $this->assertArrayHasKey($path, $this->written, 'no build-context ignore file was written');

        return $this->written[$path];
    }

    public function test_every_build_in_the_run_file_gets_an_ignore_file(): void
    {
        $this->keep([
            'docker-compose.panelalpha.yml' => <<<'YAML'
            services:
              web:
                build: { context: ., dockerfile: Dockerfile }
              worker:
                build: .
              api:
                build: ./api
              db:
                image: mariadb:11
            YAML,
            'Dockerfile' => "FROM httpd\nCOPY . /var/www/html\n",
            'api/Dockerfile' => "FROM node\nCOPY . .\n",
        ]);

        $root = self::PROJECT_DIR . '/Dockerfile.dockerignore';
        $api = self::PROJECT_DIR . '/api/Dockerfile.dockerignore';
        $this->assertSame([$root, $api], array_keys($this->written));
        foreach ([$root, $api] as $path) {
            $this->assertStringStartsWith(BuildContextIgnore::MARKER, $this->written[$path]);
            $this->assertContains('.git', explode("\n", $this->written[$path]));
        }
    }

    public function test_a_run_file_that_builds_nothing_writes_nothing(): void
    {
        $this->keep(['docker-compose.panelalpha.yml' => "services:\n  app:\n    image: nginx\n"]);

        $this->assertSame([], $this->written);
    }
}
