<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompileFrontendBuildFixtures;

/**
 * The manifest's `frontend_build` in the PHP host frontend pass:
 * false skips it, a command replaces the `build` script and runs even when
 * package.json has none, absent keeps the build-script rule.
 */
class HostCompileFrontendBuildTest extends TestCase
{
    use HostCompileFrontendBuildFixtures;

    public function test_absent_and_no_build_script_runs_nothing(): void
    {
        [$ran, $commands] = $this->runForPhp(['/home/acme/project/package.json' => '{"dependencies":{"jquery":"3.7.1"}}'], null);

        $this->assertFalse($ran);
        $this->assertSame([], $commands);
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

    public function test_a_command_without_a_package_json_fails_the_deploy(): void
    {
        $this->expectExceptionMessage('frontend_build');
        $this->runForPhp([], 'yarn install');
    }
}
