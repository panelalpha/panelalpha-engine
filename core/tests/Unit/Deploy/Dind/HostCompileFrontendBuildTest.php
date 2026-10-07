<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The manifest's `frontend_build` in the PHP host frontend pass:
 * false skips it, a command replaces the `build` script and runs even when
 * package.json has none, absent keeps the build-script rule.
 */
class HostCompileFrontendBuildTest extends TestCase
{
    /** @param array<string, string> $files absolute account path => contents */
    private function system(array $files, int $exit = 0, string $stderr = ''): System
    {
        return new class ($files, $exit, $stderr) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files */
            public function __construct(private array $files, private int $exit = 0, private string $stderr = '')
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
                $process = new Process([
                    'php', '-r', 'echo "Running webpack ..."; fwrite(STDERR, ' . var_export($this->stderr, true) . '); exit(' . $this->exit . ');',
                ]);
                $process->run();

                return $process;
            }
        };
    }

    private function project(System $system, ?SharedBaseImages $bases = null): Dind
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
        if ($bases !== null) {
            $inner = $this->createStub(InnerDocker::class);
            $inner->method('bases')->willReturn($bases);
            $dind->method('innerDocker')->willReturn($inner);
        }

        return $dind;
    }

    /**
     * @param array<string, string> $files
     * @return array{0: bool, 1: list<list<string>>} whether it ran, and the containers
     */
    private function runForPhp(
        array $files,
        string|false|null $frontendBuild,
        ?string $phpImage = null,
        ?SharedBaseImages $bases = null,
        int $exit = 0,
        string $stderr = ''
    ): array {
        $system = $this->system($files + [
            '/home/acme/project/composer.json' => '{"require":{"php":"^8.2"}}',
            '/home/acme/project/vendor/autoload.php' => '<?php',
        ], $exit, $stderr);
        // The Node image choice scans the real disk, where /home/acme is not.
        set_error_handler(static fn (int $no, string $message): bool => str_starts_with($message, 'scandir('), E_WARNING);
        try {
            $ran = (new HostCompile($this->project($system, $bases)))
                ->runForPhp('/home/acme/project', [], '', $frontendBuild, $phpImage);
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

    /**
     * Firefly III: the root package.json only declares `workspaces` (and a
     * patch-package postinstall); `vite build` is resources/assets/v3's own
     * script. No frontend build ran, and every page 500'd on
     * ViteManifestNotFoundException.
     */
    public function test_a_workspace_root_without_a_build_script_builds_its_workspaces(): void
    {
        [$ran, $commands] = $this->runForPhp([
            '/home/acme/project/package.json' => '{"scripts":{"postinstall":"patch-package --error-on-fail"},'
                . '"workspaces":["resources/assets/v3"]}',
            '/home/acme/project/package-lock.json' => '{}',
        ], null);

        $this->assertTrue($ran);
        $this->assertCount(1, $commands);
        $this->assertStringEndsWith(' && npm run build --workspaces --if-present', (string) end($commands[0]));
    }

    public function test_each_manager_builds_its_workspaces_its_own_way(): void
    {
        $this->assertSame('pnpm -r --if-present run build', JsPackageManager::workspacesScriptCommand('pnpm', 'build'));
        $this->assertSame("bun run --filter '*' build", JsPackageManager::workspacesScriptCommand('bun', 'build'));
        $this->assertSame('npm run build --workspaces --if-present', JsPackageManager::workspacesScriptCommand('yarn', 'build'));
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

    /**
     * selfoss: postinstall runs `npm run install-dependencies`, which ends in
     * `composer install`, and a Node-only image exits 127 on it.
     */
    public function test_a_script_calling_composer_builds_in_the_php_image_with_node(): void
    {
        $package = json_encode(['scripts' => [
            'postinstall' => 'npm run install-dependencies',
            'install-dependencies' => 'npm run install-dependencies:client && npm run install-dependencies:server',
            'install-dependencies:client' => 'npm ci --prefix client/',
            'install-dependencies:server' => 'composer install',
            'build' => 'npm run --prefix client build',
        ]]);
        $bases = $this->createMock(SharedBaseImages::class);
        $bases->expects($this->once())->method('ensureNodeBuild')
            ->with('panelalpha/php:8.3-apache-bookworm-paXXXX', $this->stringStartsWith('node:'))
            ->willReturn('panelalpha/build-node:php-pa12345678');

        [, $commands] = $this->runForPhp(
            ['/home/acme/project/package.json' => (string) $package],
            null,
            'panelalpha/php:8.3-apache-bookworm-paXXXX',
            $bases
        );

        $this->assertContains('panelalpha/build-node:php-pa12345678', $commands[0]);
    }

    /** OSPOS: `build` is `gulp default`, and a gulp task runs `composer licenses`. */
    public function test_a_gulp_task_calling_composer_builds_in_the_php_image_with_node(): void
    {
        $bases = $this->createMock(SharedBaseImages::class);
        $bases->expects($this->once())->method('ensureNodeBuild')
            ->willReturn('panelalpha/build-node:php-pa12345678');

        [, $commands] = $this->runForPhp(
            [
                '/home/acme/project/package.json' => '{"scripts":{"build":"gulp default","gulp":"gulp"}}',
                '/home/acme/project/gulpfile.js' => "gulp.task('update-licenses', function () {\n"
                    . "    return Promise.all([run_completion(run('composer licenses --format=json --no-dev > public/license/composer.LICENSES').exec())]);\n});\n",
            ],
            null,
            'panelalpha/php:8.3-apache-bookworm-paXXXX',
            $bases
        );

        $this->assertContains('panelalpha/build-node:php-pa12345678', $commands[0]);
    }

    public function test_a_frontend_that_never_calls_php_keeps_the_node_image(): void
    {
        $bases = $this->createMock(SharedBaseImages::class);
        $bases->expects($this->never())->method('ensureNodeBuild');

        [, $commands] = $this->runForPhp(
            ['/home/acme/project/package.json' => '{"scripts":{"build":"vite build","postinstall":"patch-package"}}'],
            null,
            'panelalpha/php:8.3-apache-bookworm-paXXXX',
            $bases
        );

        $this->assertNotContains('panelalpha/php:8.3-apache-bookworm-paXXXX', $commands[0]);
    }

    /**
     * Yarn PnP's link step deletes node_modules, which here is the isolated
     * bind mount: `EBUSY: rmdir '/app/node_modules'` (arcanis/secretsanta).
     */
    public function test_yarn_pnp_installs_into_node_modules_when_it_is_isolated(): void
    {
        [, $commands] = $this->runForPhp([
            '/home/acme/project/package.json' => '{"packageManager":"yarn@4.5.1","scripts":{"build":"vite build"}}',
            '/home/acme/project/yarn.lock' => "__metadata:\n  version: 8\n",
        ], null);

        $this->assertContains('YARN_NODE_LINKER=node-modules', $commands[0]);
        $this->assertContains('/var/cache/panelalpha/projects/acme/node_modules:/app/node_modules', $commands[0]);
    }

    /**
     * A host-run Express app on Yarn 4 with no .yarnrc.yml: PnP wrote .pnp.cjs
     * and no node_modules, and the deploy failed "Install finished but
     * node_modules is missing". That compile keeps node_modules in the project.
     */
    public function test_yarn_pnp_installs_into_node_modules_for_a_host_run_app_too(): void
    {
        $system = $this->system([
            '/home/acme/project/package.json' => '{"packageManager":"yarn@4.5.1","dependencies":{"express":"^4.21.0"}}',
            '/home/acme/project/yarn.lock' => "__metadata:\n  version: 8\n",
        ]);
        $compile = new HostCompile($this->project($system));

        (new \ReflectionMethod(HostCompile::class, 'runContainer'))->invoke(
            $compile,
            '/home/acme/project',
            'node:22-bookworm',
            'yarn install --immutable',
            '',
            [],
            false,
            true,
            ''
        );

        $this->assertContains('YARN_NODE_LINKER=node-modules', $system->commands[0]);
        $this->assertNotContains('/var/cache/panelalpha/projects/acme/node_modules:/app/node_modules', $system->commands[0]);
    }

    public function test_a_project_linker_other_than_pnp_is_left_alone(): void
    {
        [, $commands] = $this->runForPhp([
            '/home/acme/project/package.json' => '{"packageManager":"yarn@4.5.1","scripts":{"build":"vite build"}}',
            '/home/acme/project/.yarnrc.yml' => "nodeLinker: node-modules\n",
            '/home/acme/project/yarn.lock' => "__metadata:\n  version: 8\n",
        ], null);

        $this->assertNotContains('YARN_NODE_LINKER=node-modules', $commands[0]);
    }

    /**
     * Chamilo: webpack SIGKILLed by the memory cgroup prints nothing, and the
     * deploy reported the tail of yarn's successful output as the cause.
     */
    public function test_a_build_killed_with_status_137_says_it_ran_out_of_memory(): void
    {
        try {
            $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"encore production"}}'], null, exit: 137);
            $this->fail('the build should have failed');
        } catch (\Exception $e) {
            $this->assertStringStartsWith('The host build container ran out of memory', $e->getMessage());
            $this->assertMatchesRegularExpression('/limit of \d+ MB/', $e->getMessage());
            $this->assertStringContainsString('Running webpack', $e->getMessage());
            $this->assertStringContainsString(
                'DEPLOY_BUILD_MEMORY',
                (string) \App\Lib\Deploy\DeployLog\DeployFailureExplainer::explain($e->getMessage())
            );
        }
    }

    /** yarn 4 exits 129 without a word; the build script's own OOM report is what says so. */
    public function test_an_oom_kill_reported_by_the_build_script_says_it_ran_out_of_memory(): void
    {
        try {
            $this->runForPhp(
                ['/home/acme/project/package.json' => '{"scripts":{"build":"encore production"}}'],
                null,
                exit: 129,
                stderr: DindHostBuilder::OOM_REPORT . "\n"
            );
            $this->fail('the build should have failed');
        } catch (\Exception $e) {
            $this->assertStringStartsWith('The host build container ran out of memory', $e->getMessage());
        }
    }

    public function test_the_host_build_script_reports_an_oom_kill(): void
    {
        [, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], null);

        $this->assertStringStartsWith("trap '", (string) end($commands[0]));
        $this->assertStringContainsString('/sys/fs/cgroup/memory.events', (string) end($commands[0]));
    }

    public function test_any_other_failure_keeps_the_builds_own_output(): void
    {
        try {
            $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"encore production"}}'], null, exit: 1);
            $this->fail('the build should have failed');
        } catch (\Exception $e) {
            $this->assertSame('Running webpack ...', $e->getMessage());
        }
    }
}
