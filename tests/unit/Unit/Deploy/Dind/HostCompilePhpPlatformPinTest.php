<?php

namespace Tests\Unit\Deploy\Dind;

use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompilePhpPlatformPinFixtures;

/**
 * The host PHP build resolves for the engine's chosen PHP minor, not for the
 * project's own `config.platform`.
 *
 * Two Composer passes run on the host and only one pinned the platform.
 * `composerInstallArgv()` pinned; `runPhpBuild()` -- the pass that actually
 * produces the `vendor/` the application runs on -- did not, so the project's
 * pin won. YOURLS is how that was found: `config.platform` 8.1.0 beside
 * `require.php ^8.4`, so the engine built the 8.4 base image and then failed
 * the resolve against 8.1 with "overridden via config.platform, actual:
 * 8.4.25". htmly ships the same shape pinned at 7.2.
 *
 * The real `runPhpBuild()` runs end to end, so the guard that keeps a build
 * inside ~/project is exercised too.
 */
class HostCompilePhpPlatformPinTest extends TestCase
{
    use HostCompilePhpPlatformPinFixtures;

    /**
     * A manifest with no dependency step has nothing for a pin to precede, and
     * runPhpBuild() runs nothing at all -- the pin must not create a build that
     * did not exist.
     */
    public function test_an_empty_dependency_step_runs_nothing_at_all(): void
    {
        $this->assertNull($this->build(
            ['composer.json' => '{"require":{"php":"^8.4"}}'],
            ['install_command' => '', 'build_command' => ''],
            '',
            false
        ));
    }
}
