<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * HostCompile::run() where a compile does not go as planned: a bun compile retried on Node, a base image
 * the host cannot refresh, a Go program's embedded frontend, a Rust binary missing libraries at runtime.
 */
class HostCompileFallbacksTest extends TestCase
{
    private const NODE = 'node:22-bookworm-slim';

    private string $home = '';

    private string $username = '';

    private ?DeployLogger $deploy = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.step_idle_timeout' => 0]);
        $this->username = 'hcfb'.bin2hex(random_bytes(3));
        $this->home = sys_get_temp_dir().'/pa-hcfb-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/project', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        (new Process(['rm', '-rf', $this->home]))->run();
        parent::tearDown();
    }

    public function test_a_failed_bun_compile_is_retried_on_node_with_the_node_commands(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->siteProject(['bun.lockb' => '']);
        $install = 'HUSKY=0 LEFTHOOK=0 CI=1 bun install';

        $system = $this->compile([
            'exits' => [[1, 'bun: illegal instruction'], [0, '']],
            'onBuild' => fn (int $n) => $n === 2 ? $this->write('dist/index.html', '<!doctype html>') : null,
        ]);

        $this->assertSame([HostNodeBuild::BUN_IMAGE, self::NODE], array_column($system->builds, 2));
        $this->assertSame([HostNodeBuild::bunInstallCommand($install), HostNodeBuild::bunBuildCommand('bun run build')], array_slice($system->builds[0], 3, 2));
        $this->assertSame([HostNodeBuild::nodeInstallCommand($install), HostNodeBuild::nodeBuildCommand('bun run build')], array_slice($system->builds[1], 3, 2));
        $this->assertContains('Bun compile failed, retrying with Node: bun: illegal instruction', $this->lines());
    }

    public function test_a_failed_node_compile_is_not_retried(): void
    {
        $this->siteProject(['package-lock.json' => '{}']);

        try {
            $this->compile(['exits' => [[1, 'vite: not found']]]);
            $this->fail('the failed compile must surface');
        } catch (\Exception $e) {
            $this->assertSame('vite: not found', $e->getMessage());
        }
    }

    public function test_a_lockfile_the_frozen_install_refuses_is_installed_from_package_json(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->siteProject(['package-lock.json' => '{}']);

        $system = $this->compile([
            'exits' => [[1, 'npm error `npm ci` can only install packages when your package.json and package-lock.json are in sync.'], [0, '']],
            'onBuild' => fn (int $n) => $n === 2 ? $this->write('dist/index.html', '<!doctype html>') : null,
        ]);

        $this->assertCount(2, $system->builds);
        $this->assertStringContainsString('npm install', $system->builds[1][3]);
    }

    /** A build script's own `npm ci` in a subdirectory refused its lockfile: unfreezing the project's install changes nothing. */
    public function test_a_lockfile_refusal_after_the_install_finished_is_not_retried(): void
    {
        $this->siteProject(['package-lock.json' => '{}']);
        $refusal = 'npm error `npm ci` can only install packages when your package.json and package-lock.json are in sync.';

        try {
            $this->compile(['exits' => [[1, $refusal, HostNodeBuild::INSTALLED_MARKER."\n"]]]);
            $this->fail('the failed build must surface');
        } catch (\Exception $e) {
            $this->assertSame($refusal, $e->getMessage());
        }
    }

    public function test_a_base_image_the_host_cannot_refresh_is_built_with_the_copy_it_has(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->siteProject(['package-lock.json' => '{}', 'dist/index.html' => '<!doctype html>']);

        $system = $this->compile(['failingExec' => [Privileged::host()->refreshPublicImage(self::NODE)]]);

        $this->assertSame([self::NODE], array_column($system->builds, 2));
        $this->assertContains('Could not refresh base image '.self::NODE.' on the host; building with the copy it has: pull refused', $this->lines());
        $this->assertContains('Static assets compiled', $this->lines());
    }

    public function test_a_webpack_4_build_runs_with_the_openssl_legacy_provider(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->siteProject(['package-lock.json' => '{}', 'dist/index.html' => '<!doctype html>'], ['webpack' => '^4.46.0']);

        $system = $this->compile([]);

        $this->assertSame(NodeRuntime::LEGACY_OPENSSL_ENV.' && npm run build', $system->builds[0][4]);
        $this->assertContains('Webpack 4 build: enabling the OpenSSL legacy provider', $this->lines());
    }

    public function test_a_checkout_outside_the_account_project_directory_is_refused(): void
    {
        $this->siteProject(['package-lock.json' => '{}']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing host build outside the account project directory');

        $this->compile(['accountHome' => $this->home.'/elsewhere']);
    }

    public function test_an_account_without_linux_ids_is_given_nothing(): void
    {
        $this->siteProject(['package-lock.json' => '{}', 'dist/index.html' => '<!doctype html>']);

        $system = $this->compile(['ids' => false]);

        $this->assertCount(1, $system->builds);
        $this->assertNotContains(Privileged::files()->changeOwnerRecursively('1001:1001', $this->home.'/project/dist'), $system->execs);
    }

    public function test_a_go_program_whose_embedded_frontend_fails_to_build_is_compiled_without_it(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->goProject();

        $system = $this->compile(['exits' => [[1, 'vite: not found'], [0, '']]]);

        $this->assertSame([self::NODE, 'golang:1.22-alpine'], array_column($system->builds, 2));
        $this->assertSame(['node', 'plain'], array_column($system->builds, 7));
        $this->assertSame('web', $system->builds[0][8]);
        $this->assertContains('Building the frontend in web/ first: main.go: web/dist is embedded by the Go build and is not in the checkout', $this->lines());
        $this->assertContains('The frontend build in web/ failed; compiling Go without it: vite: not found', $this->lines());
        $this->assertContains(Privileged::files()->changeOwnerRecursively('1001:1001', $this->home.'/project/.'), $system->execs);
    }

    public function test_a_cancel_during_a_go_programs_frontend_build_stops_the_compile(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->goProject();

        try {
            $this->compile(['onBuild' => fn () => DeployLogger::requestCancel($this->username)]);
            $this->fail('the cancel must stop the compile');
        } catch (DeployCancelledException) {
        }

        $this->assertCount(0, array_filter($this->lines(), static fn (string $line): bool => str_contains($line, 'compiling Go without it')));
    }

    public function test_a_rust_binary_missing_libraries_in_its_runtime_image_has_them_bundled(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->rustProject();
        $this->write(RustRuntimeLibraries::MISSING_FILE, "libpq.so.5\n");

        $system = $this->compile([]);

        $images = array_column($system->builds, 2);
        $this->assertCount(4, $images);
        $this->assertSame([$images[0], 'rust:1-slim-bookworm', $images[0], 'rust:1-slim-bookworm'], $images);
        $this->assertSame(
            [RustRuntimeLibraries::checkScript(), RustRuntimeLibraries::bundleScript(), RustRuntimeLibraries::verifyScript()],
            array_column(array_slice($system->builds, 1), 4),
        );
        $this->assertContains("Bundling the libraries rust:1-slim-bookworm lacks from {$images[0]} into ".RustRuntimeLibraries::DIR, $this->lines());
    }

    public function test_a_cancel_while_the_rust_binary_is_checked_is_not_taken_for_a_failed_check(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->rustProject();

        try {
            $this->compile(['onBuild' => fn (int $n) => $n === 2 ? DeployLogger::requestCancel($this->username) : null]);
            $this->fail('the cancel must stop the compile');
        } catch (DeployCancelledException) {
        }

        $this->assertCount(0, array_filter($this->lines(), static fn (string $line): bool => str_starts_with($line, 'Could not check the Rust binary')));
    }

    public function test_a_static_site_given_a_build_stage_is_checked_for_its_index_at_the_root(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('index.html', '<!doctype html>');
        app(DeployPlanContext::class)->set(DeployPlan::fromArray(['build' => [['run' => 'npx tailwindcss -o style.css']]]));

        $system = $this->compile([]);

        $this->assertSame('npx tailwindcss -o style.css', $system->builds[0][4] ?? null);
        $this->assertContains(Privileged::files()->changeOwnerRecursively('1001:1001', $this->home.'/project/.'), $system->execs);
        $this->assertContains('Static assets compiled', $this->lines());
    }

    /** Astro runs mounted when it is a server; its static recipe is served by nginx, so its page is checked for. */
    public function test_a_static_astro_build_without_its_page_fails_rather_than_serving_an_empty_directory(): void
    {
        $this->astroProject("export default {};\n");

        $this->expectExceptionMessage('Static build finished but dist/index.html is missing');

        $this->compile([]);
    }

    public function test_a_static_astro_page_written_elsewhere_is_moved_to_the_served_directory(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->astroProject("export default { adapter: node({ mode: 'standalone' }), output: 'static' };\n");

        $this->compile(['onBuild' => fn (): mixed => $this->write('dist/index.html', '<!doctype html>')]);

        $this->assertContains('The build wrote index.html to dist/, not to dist/client/; moved it to dist/client/, where it is served from', $this->lines());
        $this->assertContains('Static assets compiled', $this->lines());
    }

    public function test_a_nitro_output_the_last_deploy_moved_up_is_cleared_before_the_build(): void
    {
        $this->nuxtProject();
        $this->write('.output/'.StandaloneNodeServe::RELOCATED_MARKER, '');
        $execs = [];

        try {
            $this->compile(['onExec' => static function (array $argv) use (&$execs): string {
                $execs[] = $argv;

                return '';
            }]);
            $this->fail('a build that leaves no server entry must fail');
        } catch (\Exception $e) {
            $this->assertSame(StandaloneNodeServe::missingEntryMessage(), $e->getMessage());
        }

        $output = $this->home.'/project/.output';
        $this->assertContains(Privileged::files()->removeTree($output), $execs);
        $this->assertContains(StandaloneNodeServe::nestedEntriesArgv($this->home.'/project'), $execs);
    }

    public function test_a_nitro_build_whose_nested_outputs_cannot_be_listed_has_no_entry(): void
    {
        $this->nuxtProject();
        $listing = StandaloneNodeServe::nestedEntriesArgv($this->home.'/project');

        $this->expectExceptionMessage(StandaloneNodeServe::missingEntryMessage());

        $this->compile(['onExec' => static function (array $argv) use ($listing): string {
            if ($argv === $listing) {
                throw new \RuntimeException('find: permission denied');
            }

            return '';
        }]);
    }

    public function test_a_workspace_apps_nitro_output_is_moved_up_and_served(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->nuxtProject();
        $root = $this->home.'/project';
        $listing = StandaloneNodeServe::nestedEntriesArgv($root);
        $rename = Privileged::files()->rename($root.'/apps/web/.output', $root.'/.output');

        $system = $this->compile(['onExec' => function (array $argv) use ($listing, $rename, $root): string {
            if ($argv === $rename) {
                $this->write('.output/server/index.mjs', 'export {}');
            }

            return $argv === $listing ? $root."/apps/web/.output/server/index.mjs\n" : '';
        }]);

        $this->assertContains($rename, $system->execs);
        $this->assertContains(Privileged::files()->changeOwnerRecursively('1001:1001', $root.'/.output'), $system->execs);
        $this->assertContains(Privileged::accountFiles(1001, 1001)->touch($root.'/.output/'.StandaloneNodeServe::RELOCATED_MARKER), $system->execs);
        $this->assertContains('The build wrote its server to apps/web/.output; moved it to .output, where it is served from', $this->lines());
        $this->assertContains('Application compiled', $this->lines());
    }

    public function test_a_java_release_the_jdk_refuses_is_compiled_again_and_run_on_a_newer_jdk(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('pom.xml', '<project><modelVersion>4.0.0</modelVersion><groupId>a</groupId><artifactId>app</artifactId><version>1</version>'
            .'<properties><maven.compiler.release>21</maven.compiler.release></properties></project>');
        $writer = $this->createMock(ComposeWriter::class);
        $writer->expects($this->once())->method('replaceAppImage')->with('maven:3.9-eclipse-temurin-21', 'maven:3.9-eclipse-temurin-25', '1001:1001');

        $system = $this->compile([
            'exits' => [[1, 'Fatal error compiling: error: release version 25 not supported -> [Help 1]'], [0, '']],
            'composeWriter' => $writer,
        ]);

        $this->assertSame(['maven:3.9-eclipse-temurin-21', 'maven:3.9-eclipse-temurin-25'], array_column($system->builds, 2));
        $this->assertContains('The build refused the JDK in maven:3.9-eclipse-temurin-21; compiling again with'
            .' maven:3.9-eclipse-temurin-25, the JDK it asked for', $this->lines());
    }

    public function test_a_php_project_without_a_package_json_has_no_frontend_to_build(): void
    {
        $this->write('composer.json', '{"require":{"php":"^8.3"}}');

        $system = $this->compile(['call' => fn (HostCompile $compile): bool => $compile->runForPhp($this->home.'/project', ['composer.json' => true])]);

        $this->assertFalse($system->result);
        $this->assertSame([], $system->builds);
    }

    public function test_a_php_frontend_whose_composer_install_fails_stops_there(): void
    {
        $this->write('composer.json', '{"require":{"php":"^8.3"}}');
        $this->write('package.json', '{"name":"theme","scripts":{"build":"vite build"},"devDependencies":{"vite":"^5.0.0"}}');

        try {
            $this->compile([
                'exits' => [[1, '']],
                'call' => fn (HostCompile $compile): bool => $compile->runForPhp($this->home.'/project', ['composer.json' => true, 'package.json' => true]),
            ]);
            $this->fail('the failed Composer install must surface');
        } catch (\Exception $e) {
            $this->assertSame('Host Composer install failed', $e->getMessage());
        }
    }

    public function test_a_php_frontend_whose_bun_build_fails_is_retried_on_node(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('package.json', '{"name":"theme","scripts":{"build":"vite build"},"devDependencies":{"vite":"^5.0.0"}}');
        $this->write('bun.lockb', '');

        $system = $this->compile([
            'exits' => [[1, 'bun: crashed'], [0, '']],
            'call' => fn (HostCompile $compile): bool => $compile->runForPhp($this->home.'/project', ['package.json' => true, 'bun.lockb' => true]),
        ]);

        $this->assertTrue($system->result);
        $this->assertSame([HostNodeBuild::BUN_IMAGE, self::NODE], array_column($system->builds, 2));
        $this->assertContains('Bun compile failed, retrying with Node: bun: crashed', $this->lines());
    }

    public function test_a_failed_host_php_build_reports_its_output(): void
    {
        $this->write('composer.json', '{"require":{"php":"^8.3"}}');

        try {
            $this->compile([
                'exits' => [[1, 'Your requirements could not be resolved']],
                'call' => static fn (HostCompile $compile): mixed => $compile->runPhpBuild('php:8.3-cli', ['install_command' => 'composer install --no-dev'], '', true),
            ]);
            $this->fail('the failed PHP build must surface');
        } catch (\Exception $e) {
            $this->assertSame('Your requirements could not be resolved', $e->getMessage());
        }
    }

    private function astroProject(string $config): void
    {
        $this->write('package.json', '{"name":"site","private":true,"scripts":{"build":"astro build"},"dependencies":{"astro":"^5.0.0"}}');
        $this->write('package-lock.json', '{}');
        $this->write('astro.config.mjs', $config);
        mkdir($this->home.'/project/node_modules');
    }

    private function nuxtProject(): void
    {
        $this->write('package.json', '{"name":"n","private":true,"scripts":{"build":"nuxt build"},"dependencies":{"nuxt":"^3.12.0"}}');
        $this->write('package-lock.json', '{}');
        $this->write('nuxt.config.ts', "export default defineNuxtConfig({})\n");
        mkdir($this->home.'/project/node_modules');
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, string>  $devDependencies
     */
    private function siteProject(array $files, array $devDependencies = []): void
    {
        $this->write('package.json', (string) json_encode([
            'name' => 'site',
            'private' => true,
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^5.0.0'] + $devDependencies,
        ]));
        $this->write('index.html', '<!doctype html><title>x</title>');
        foreach ($files as $path => $contents) {
            $this->write($path, $contents);
        }
    }

    private function goProject(): void
    {
        $this->write('go.mod', "module example.com/app\n\ngo 1.22\n");
        $this->write('main.go', "package main\n\nimport \"embed\"\n\n//go:embed web/dist\nvar dist embed.FS\n\nfunc main() {}\n");
        $this->write('web/package.json', '{"name":"web","scripts":{"build":"vite build"},"devDependencies":{"vite":"^5.0.0"}}');
    }

    private function rustProject(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"app\"\nversion = \"0.1.0\"\n\n[dependencies]\n");
        $this->write('src/main.rs', "fn main() {}\n");
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->home.'/project/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_map(static fn (array $line): string => (string) $line['msg'], $this->deploy?->entries() ?? []);
    }

    /**
     * @param  array{exits?: list<array{0: int, 1: string, 2?: string}>, onBuild?: callable(int): mixed, failingExec?: list<list<string>>, onExec?: callable(list<string>): mixed, accountHome?: string, ids?: bool, call?: callable(HostCompile): mixed, composeWriter?: ComposeWriter}  $behaviour
     */
    private function compile(array $behaviour): object
    {
        $system = new class($behaviour) extends System
        {
            /** @var list<list<string>> */
            public array $builds = [];

            /** @var list<list<string>> */
            public array $execs = [];

            public mixed $result = null;

            /** @param  array{exits?: list<array{0: int, 1: string, 2?: string}>, onBuild?: callable(int): mixed, failingExec?: list<list<string>>}  $behaviour */
            public function __construct(private array $behaviour) {}

            public function filesystem(): SystemFilesystem
            {
                return new class($this) extends SystemFilesystem
                {
                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) @file_get_contents($path);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->execs[] = (array) $cmd;
                if (in_array((array) $cmd, $this->behaviour['failingExec'] ?? [], true)) {
                    throw new \RuntimeException('pull refused');
                }
                if (isset($this->behaviour['onExec'])) {
                    return (string) ($this->behaviour['onExec'])((array) $cmd);
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->build((array) $cmd);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->build((array) $cmd);
            }

            /** @param  list<string>  $argv */
            private function build(array $argv): Process
            {
                if (LocalRoot::isRequest($argv)) {
                    $this->execs[] = $argv;

                    return FakeProcess::ok();
                }
                $this->builds[] = $argv;
                if (isset($this->behaviour['onBuild'])) {
                    ($this->behaviour['onBuild'])(count($this->builds));
                }
                [$code, $stderr, $stdout] = ($this->behaviour['exits'][count($this->builds) - 1] ?? [0, '']) + [2 => ''];

                return FakeProcess::of($code, $stdout, $stderr);
            }
        };

        $model = new User;
        $model->username = $this->username;
        $model->details = ($behaviour['ids'] ?? true) ? ['UID' => 1001, 'GID' => 1001] : [];

        $builder = $this->createStub(HostBuilder::class);
        $builder->method('prepareCacheArgv')->willReturn(['prepare-cache']);
        $builder->method('composerInstallArgv')->willReturn(['composer-install']);
        $builder->method('phpBuildArgv')->willReturnCallback(static fn (EngineAccount $account, string $image): array => ['php-build', $image]);
        $builder->method('memoryLimitMb')->willReturn(2048);
        $builder->method('memoryOrigin')->willReturn(new BuildMemory('2048m'));
        $builder->method('nodeBuildArgv')->willReturnCallback(
            static fn (EngineAccount $account, string $image, string $install, string $build, array $env = [], bool $isolate = true, bool $isNode = true, string $appRoot = ''): array => [
                'node-build', $account->projectDir(), $image, $install, $build, (string) json_encode($env), $isolate ? 'isolated' : 'shared', $isNode ? 'node' : 'plain', $appRoot,
            ]
        );
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn($builder);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn(new DindImageStore);

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn($this->home.'/project');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount($this->username, $behaviour['accountHome'] ?? $this->home, '1001:1001'));
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        if (isset($behaviour['composeWriter'])) {
            $dind->method('composeWriter')->willReturn($behaviour['composeWriter']);
        }

        $call = $behaviour['call'] ?? static fn (HostCompile $compile): mixed => $compile->run();
        $system->result = $call(new HostCompile($dind));

        return $system;
    }
}
