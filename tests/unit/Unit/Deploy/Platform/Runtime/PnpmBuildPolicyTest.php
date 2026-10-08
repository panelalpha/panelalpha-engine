<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use Tests\TestCase;

/**
 * `pnpm install --dangerously-allow-all-builds` sets neverBuiltDependencies.
 * pnpm 10 aborts with ERR_PNPM_CONFIG_CONFLICT_BUILT_DEPENDENCIES when that
 * meets an onlyBuiltDependencies the repo declared itself — which is what
 * sveltejs/realworld does in pnpm-workspace.yaml.
 */
class PnpmBuildPolicyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pnpm-policy-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function pnpmInstall(array $package = [], ?string $projectDir = null): string
    {
        return JsPackageManager::installCommand(
            'pnpm',
            ['pnpm-lock.yaml' => true],
            $package + ['packageManager' => 'pnpm@10.20.0'],
            $projectDir
        );
    }

    /**
     * HedgeDoc's, verbatim, comment and all. pnpm 11 renamed the setting to
     * `allowBuilds`, and knowing only pnpm 10's spellings meant not seeing a
     * policy that was plainly there.
     *
     * The flag overrode `better-sqlite3: false`, node-gyp ran anyway, and the
     * build died on `Could not find any Python installation to use` in
     * node:24-bookworm-slim, which has none -- the exact outcome the comment
     * beside that line exists to avoid.
     */
    public function test_flag_is_dropped_for_pnpm_11_allow_builds(): void
    {
        file_put_contents($this->dir . '/pnpm-workspace.yaml', <<<'YAML'
            packages:
              - backend
              - frontend

            allowBuilds:
              # Produce real build artefacts (native bindings, downloaded binaries).
              "@parcel/watcher": true
              # Ships prebuilds for every platform (incl. linuxmusl-x64 for the Alpine
              # images); building from source would need Python, which those images lack.
              better-sqlite3: false
            YAML);

        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall([], $this->dir),
            "a project that says better-sqlite3: false must not be made to build it"
        );
    }

    /** The other pnpm 11 spelling, for the same reason. */
    public function test_flag_is_dropped_for_ignored_built_dependencies(): void
    {
        file_put_contents(
            $this->dir . '/pnpm-workspace.yaml',
            "ignoredBuiltDependencies:\n  - cypress\n"
        );

        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall([], $this->dir)
        );
    }

    /** And in package.json, where a single-package repo puts it. */
    public function test_flag_is_dropped_for_allow_builds_in_package_json(): void
    {
        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall(['pnpm' => ['allowBuilds' => ['better-sqlite3' => false]]], $this->dir)
        );
    }

    public function test_flag_is_added_when_the_project_declares_no_policy(): void
    {
        $this->assertStringContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall([], $this->dir)
        );
    }

    public function test_flag_is_dropped_for_only_built_dependencies_in_workspace_yaml(): void
    {
        file_put_contents(
            $this->dir . '/pnpm-workspace.yaml',
            "onlyBuiltDependencies:\n  - esbuild\n"
        );

        $command = $this->pnpmInstall([], $this->dir);
        $this->assertStringNotContainsString('--dangerously-allow-all-builds', $command);
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $command);
    }

    public function test_flag_is_dropped_for_never_built_dependencies_in_workspace_yaml(): void
    {
        file_put_contents(
            $this->dir . '/pnpm-workspace.yaml',
            "neverBuiltDependencies:\n  - sharp\n"
        );

        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall([], $this->dir)
        );
    }

    public function test_flag_is_dropped_for_policy_declared_in_package_json(): void
    {
        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall(['pnpm' => ['onlyBuiltDependencies' => ['esbuild']]], $this->dir)
        );
    }

    /**
     * An unrelated workspace file must not disable the flag.
     */
    public function test_flag_survives_a_workspace_yaml_without_a_build_policy(): void
    {
        file_put_contents(
            $this->dir . '/pnpm-workspace.yaml',
            "packages:\n  - 'apps/*'\n"
        );

        $this->assertStringContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall([], $this->dir)
        );
    }

    public function test_no_project_dir_still_honours_package_json_policy(): void
    {
        $this->assertStringNotContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall(['pnpm' => ['neverBuiltDependencies' => []]])
        );
        $this->assertStringContainsString(
            '--dangerously-allow-all-builds',
            $this->pnpmInstall()
        );
    }
}
