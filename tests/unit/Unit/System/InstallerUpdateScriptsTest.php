<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The installer's update-path script tests pass their own checks. */
class InstallerUpdateScriptsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        yield 'the running updater keeps its own file' => ['installer-running-updater.test.sh'];
        yield 'what the root broker checks is root\'s alone after the copy' => ['installer-root-only-files.test.sh'];
        yield 'Docker restarts only without its chains' => ['installer-docker-restart.test.sh'];
        yield 'the scheduler and workers wait for migrate' => ['installer-core-background.test.sh'];
        yield 'an older core\'s tenant firewall loop stops before the copy' => ['installer-core-tenant-network.test.sh'];
        yield 'the broker rotates nothing on the new tree before the new broker' => ['installer-broker-rotation.test.sh'];
        yield 'no account port is left unbound by the tenant firewall' => ['installer-tenant-firewall.test.sh'];
    }

    #[DataProvider('scripts')]
    public function test_the_script_passes_its_own_checks(string $script): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is needed');
        }

        exec(escapeshellarg($bash).' '.escapeshellarg(dirname(__DIR__, 4).'/tests/scripts/'.$script).' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString(' passed, 0 failed', implode("\n", $out));
    }
}
