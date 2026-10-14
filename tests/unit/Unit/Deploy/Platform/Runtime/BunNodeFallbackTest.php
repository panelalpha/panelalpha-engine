<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use Tests\TestCase;

/**
 * A bun-lockfile project's install/build are bun-flavoured. When the Bun host
 * compile fails and HostCompile retries with the Node image, replaying them
 * unchanged died with `sh: 1: bun: not found`, so the fallback never worked.
 */
class BunNodeFallbackTest extends TestCase
{
    public function test_node_install_command_replaces_bun_install(): void
    {
        $install = JsPackageManager::installCommand('bun', ['bun.lock' => true]);
        $this->assertStringContainsString('bun install', $install);

        $fallback = HostNodeBuild::nodeInstallCommand($install);
        $this->assertStringNotContainsString('bun', $fallback);
        // bun.lock is not an npm lockfile, so `npm ci` would have nothing to read.
        $this->assertStringContainsString('npm install', $fallback);
        $this->assertStringNotContainsString('npm ci', $fallback);
    }

    public function test_node_install_command_leaves_non_bun_commands_alone(): void
    {
        $npm = JsPackageManager::installCommand('npm', ['package-lock.json' => true]);
        $this->assertSame($npm, HostNodeBuild::nodeInstallCommand($npm));
    }

    public function test_node_build_command_translates_bun_run(): void
    {
        $this->assertSame(
            'npm run build',
            HostNodeBuild::nodeBuildCommand('bun run build')
        );
    }

    public function test_node_build_command_translates_every_segment(): void
    {
        $this->assertSame(
            'npm run build && npx postprocess',
            HostNodeBuild::nodeBuildCommand('bun run build && bunx postprocess')
        );
    }

    public function test_node_build_command_keeps_env_prefix(): void
    {
        $this->assertSame(
            'PATH=/app/node_modules/.bin:$PATH npm run build',
            HostNodeBuild::nodeBuildCommand('PATH=/app/node_modules/.bin:$PATH bun run build')
        );
    }

    public function test_node_build_command_leaves_plain_commands_alone(): void
    {
        $this->assertSame('ng build', HostNodeBuild::nodeBuildCommand('ng build'));
        $this->assertSame('npm run build', HostNodeBuild::nodeBuildCommand('npm run build'));
    }

    /**
     * The real angular-realworld case: bun.lock present, so the recipe emits
     * `bun run build`, and the Node retry has to be able to run it.
     */
    public function test_bun_project_round_trips_to_a_runnable_node_command(): void
    {
        $pm = JsPackageManager::detectPackageManager(['bun.lock' => true]);
        $this->assertSame('bun', $pm);

        $build = JsPackageManager::scriptCommand($pm, 'build');
        $fallback = HostNodeBuild::nodeBuildCommand($build);

        $this->assertStringNotContainsString('bun', $fallback);
        $this->assertSame('npm run build', $fallback);
    }

    public function test_uses_bun_detects_env_prefixed_commands(): void
    {
        $this->assertTrue(HostNodeBuild::usesBun('HUSKY=0 CI=1 bun install'));
        $this->assertTrue(HostNodeBuild::usesBun('bunx vite build'));
        $this->assertFalse(HostNodeBuild::usesBun('npm ci --no-audit'));
        // A script *named* bun-something is not the bun binary.
        $this->assertFalse(HostNodeBuild::usesBun('bundle exec rails assets:precompile'));
    }
}
