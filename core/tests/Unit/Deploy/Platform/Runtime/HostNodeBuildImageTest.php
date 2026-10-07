<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;

/**
 * Which interpreter an on-host JS compile runs under.
 *
 * It must be the lockfile's own. Compiling an npm project under bun means
 * rewriting its install and build lines into bun's dialect and installing a
 * tree a later Node process may not be able to import - and running the
 * git-hook strip step under `bun -e`, where it hangs and pins a core until
 * somebody notices. Laravel is an npm project, so this took out the whole
 * PHP-with-assets family too.
 */
class HostNodeBuildImageTest extends TestCase
{
    private const NODE = 'node:20-bookworm-slim';

    public function test_an_npm_project_compiles_under_node(): void
    {
        $this->assertSame(
            self::NODE,
            HostNodeBuild::compilerImage('npm ci', self::NODE, 'npm')
        );
    }

    public function test_yarn_and_pnpm_projects_compile_under_node(): void
    {
        foreach (['yarn' => 'yarn install --immutable', 'pnpm' => 'pnpm install --frozen-lockfile'] as $pm => $install) {
            $this->assertSame(self::NODE, HostNodeBuild::compilerImage($install, self::NODE, $pm), $pm);
        }
    }

    public function test_a_bun_project_compiles_under_bun(): void
    {
        // The one case where the bun image is the lockfile's own interpreter.
        $this->assertSame(
            HostNodeBuild::BUN_IMAGE,
            HostNodeBuild::compilerImage('bun install', self::NODE, 'bun')
        );
    }

    public function test_the_compile_and_runtime_interpreters_agree(): void
    {
        // The property the sibling docblock asks for: a tree installed by one
        // and imported by the other fails on packages only the first resolves.
        foreach (['npm', 'yarn', 'pnpm', 'bun'] as $pm) {
            $this->assertSame(
                HostNodeBuild::runtimeImage($pm, self::NODE),
                HostNodeBuild::compilerImage($pm . ' install', self::NODE, $pm),
                $pm
            );
        }
    }

    public function test_a_command_that_is_not_a_package_manager_stays_on_node(): void
    {
        $this->assertSame(self::NODE, HostNodeBuild::compilerImage('make build', self::NODE, 'bun'));
        $this->assertSame(self::NODE, HostNodeBuild::compilerImage('', self::NODE, 'npm'));
    }

    public function test_leading_environment_assignments_do_not_hide_the_package_manager(): void
    {
        $this->assertSame(
            HostNodeBuild::BUN_IMAGE,
            HostNodeBuild::compilerImage('HUSKY=0 CI=1 bun install', self::NODE, 'bun')
        );
    }

    public function test_the_git_hook_strip_step_is_bounded(): void
    {
        // It cannot be allowed to hang: an unbounded RUN that spins pins a
        // core on the host until a person notices. `bun -e` does exactly that.
        foreach (['npm', 'bun'] as $pm) {
            $command = JsPackageManager::dockerfileStripGitHookScriptsCommand($pm);

            $this->assertStringStartsWith('timeout ', $command, $pm);
            $this->assertStringEndsWith('|| true', $command, $pm);
        }
    }

    public function test_the_strip_step_may_fail_without_failing_the_build(): void
    {
        // Stripping is a courtesy. Without it `npm install` runs husky in a
        // tree with no .git and fails loudly, which is recoverable; a hung
        // build is not.
        $this->assertStringEndsWith(
            '|| true',
            JsPackageManager::dockerfileStripGitHookScriptsCommand('npm')
        );
    }

    public function test_each_runner_is_still_the_projects_own(): void
    {
        $this->assertStringContainsString(
            " node -e ",
            JsPackageManager::dockerfileStripGitHookScriptsCommand('npm')
        );
        $this->assertStringContainsString(
            " bun -e ",
            JsPackageManager::dockerfileStripGitHookScriptsCommand('bun')
        );
    }
}
