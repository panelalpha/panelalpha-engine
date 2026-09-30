<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The manifest's `frontend_build` in the PHP host frontend pass (engine#433):
 * false skips it, a command replaces the `build` script and runs even when
 * package.json has none, absent keeps the build-script rule.
 */
class HostCompileFrontendBuildTest extends TestCase
{
    /** @param array<string, string> $files absolute account path => contents */
    private function system(array $files): System
    {
        return new class ($files) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this, $this->files) extends SystemFilesystem {
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
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                if (in_array('docker', $argv, true)) {
                    $this->commands[] = $argv;
                }
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    private function project(System $system): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder());

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        return $dind;
    }

    /**
     * @param array<string, string> $files
     * @return array{0: bool, 1: list<list<string>>} whether it ran, and the containers
     */
    private function runForPhp(array $files, string|false|null $frontendBuild): array
    {
        $system = $this->system($files + [
            '/home/acme/project/composer.json' => '{"require":{"php":"^8.2"}}',
            '/home/acme/project/vendor/autoload.php' => '<?php',
        ]);
        // The Node image choice scans the real disk, where /home/acme is not.
        set_error_handler(static fn (int $no, string $message): bool => str_starts_with($message, 'scandir('), E_WARNING);
        try {
            $ran = (new HostCompile($this->project($system)))
                ->runForPhp('/home/acme/project', [], '', $frontendBuild);
        } finally {
            restore_error_handler();
        }

        return [$ran, $system->commands];
    }

    public function test_absent_runs_the_build_script_as_before(): void
    {
        [$ran, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], null);

        $this->assertTrue($ran);
        $this->assertCount(1, $commands);
        $this->assertStringEndsWith(' && npm run build', (string) end($commands[0]));
    }

    public function test_absent_and_no_build_script_runs_nothing(): void
    {
        [$ran, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"dependencies":{"jquery":"3.7.1"}}'], null);

        $this->assertFalse($ran);
        $this->assertSame([], $commands);
    }

    /** Crater: public/build is committed and both lockfiles are stale. */
    public function test_false_skips_the_pass_even_with_a_build_script(): void
    {
        [$ran, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], false);

        $this->assertFalse($ran);
        $this->assertSame([], $commands);
    }

    /**
     * grocy: no scripts at all, and `yarn install` into public/packages is
     * the build. It sits after the cache-gated install, so it runs on a
     * node_modules cache hit too, when a re-cloned checkout lost the assets.
     */
    public function test_a_command_runs_without_any_script_and_outside_the_cache_gate(): void
    {
        [$ran, $commands] = $this->runForPhp([
            '/home/acme/project/package.json' => '{"dependencies":{"bootstrap":"4.6.2"}}',
            '/home/acme/project/yarn.lock' => '',
        ], 'yarn install');

        $this->assertTrue($ran);
        $this->assertCount(1, $commands);
        $script = (string) end($commands[0]);
        $this->assertStringEndsWith('; fi && yarn install', $script);
        $this->assertStringNotContainsString('run build', $script);
    }

    public function test_a_command_replaces_the_build_script(): void
    {
        [, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], 'npm run production');

        $script = (string) end($commands[0]);
        $this->assertStringEndsWith(' && npm run production', $script);
        $this->assertStringNotContainsString('npm run build', $script);
    }

    public function test_a_command_without_a_package_json_fails_the_deploy(): void
    {
        $this->expectExceptionMessage('frontend_build');
        $this->runForPhp([], 'yarn install');
    }
}
