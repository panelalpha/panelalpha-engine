<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\Inner\SharedBaseImages;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompileFrontendBuildFixtures;
use Tests\Support\LocalRoot;

/**
 * The manifest's `frontend_build` in the PHP host frontend pass:
 * false skips it, a command replaces the `build` script and runs even when
 * package.json has none, absent keeps the build-script rule.
 */
class HostCompileFrontendBuildTest extends TestCase
{
    use HostCompileFrontendBuildFixtures;

    public function test_absent_runs_the_build_script_as_before(): void
    {
        [$ran, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], null);

        $this->assertTrue($ran);
        $this->assertCount(1, $commands);
        $this->assertStringEndsWith(' && npm run build', LocalRoot::build($commands[0])['script']);
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
                .'"workspaces":["resources/assets/v3"]}',
            '/home/acme/project/package-lock.json' => '{}',
        ], null);

        $this->assertTrue($ran);
        $this->assertCount(1, $commands);
        $this->assertStringEndsWith(' && npm run build --workspaces --if-present', LocalRoot::build($commands[0])['script']);
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
        $script = LocalRoot::build($commands[0])['script'];
        $this->assertStringEndsWith('; fi; } && echo "'.HostNodeBuild::INSTALLED_MARKER.'" && yarn install', $script);
        $this->assertStringNotContainsString('run build', $script);
    }

    public function test_a_command_replaces_the_build_script(): void
    {
        [, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], 'npm run production');

        $script = LocalRoot::build($commands[0])['script'];
        $this->assertStringEndsWith(' && npm run production', $script);
        $this->assertStringNotContainsString('npm run build', $script);
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

        $this->assertSame('panelalpha/build-node:php-pa12345678', LocalRoot::build($commands[0])['image']);
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
                    ."    return Promise.all([run_completion(run('composer licenses --format=json --no-dev > public/license/composer.LICENSES').exec())]);\n});\n",
            ],
            null,
            'panelalpha/php:8.3-apache-bookworm-paXXXX',
            $bases
        );

        $this->assertSame('panelalpha/build-node:php-pa12345678', LocalRoot::build($commands[0])['image']);
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

        $this->assertNotSame('panelalpha/php:8.3-apache-bookworm-paXXXX', LocalRoot::build($commands[0])['image']);
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

        $this->assertSame('node-modules', LocalRoot::build($commands[0])['environment']['YARN_NODE_LINKER'] ?? null);
        $this->assertContains('/var/cache/panelalpha/projects/acme/node_modules:/app/node_modules', LocalRoot::build($commands[0])['mounts']);
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

        $this->assertSame('node-modules', LocalRoot::build($system->commands[0])['environment']['YARN_NODE_LINKER'] ?? null);
        $this->assertNotContains('/var/cache/panelalpha/projects/acme/node_modules:/app/node_modules', LocalRoot::build($system->commands[0])['mounts']);
    }

    public function test_a_project_linker_other_than_pnp_is_left_alone(): void
    {
        [, $commands] = $this->runForPhp([
            '/home/acme/project/package.json' => '{"packageManager":"yarn@4.5.1","scripts":{"build":"vite build"}}',
            '/home/acme/project/.yarnrc.yml' => "nodeLinker: node-modules\n",
            '/home/acme/project/yarn.lock' => "__metadata:\n  version: 8\n",
        ], null);

        $this->assertArrayNotHasKey('YARN_NODE_LINKER', LocalRoot::build($commands[0])['environment']);
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
                (string) DeployFailureExplainer::explain($e->getMessage())
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
                stderr: DindHostBuilder::OOM_REPORT."\n"
            );
            $this->fail('the build should have failed');
        } catch (\Exception $e) {
            $this->assertStringStartsWith('The host build container ran out of memory', $e->getMessage());
        }
    }

    public function test_the_host_build_script_reports_an_oom_kill(): void
    {
        [, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"scripts":{"build":"vite build"}}'], null);

        $script = LocalRoot::build($commands[0])['script'];
        $this->assertStringStartsWith("trap '", $script);
        $this->assertStringContainsString('/sys/fs/cgroup/memory.events', $script);
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
