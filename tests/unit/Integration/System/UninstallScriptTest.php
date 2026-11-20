<?php

namespace Tests\Integration\System;

use PHPUnit\Framework\TestCase;

/** tests/scripts/uninstall.test.sh: accounts go through the engine before the runtime that stops core. */
class UninstallScriptTest extends TestCase
{
    public function test_the_uninstaller_passes_its_own_checks(): void
    {
        $this->runShellTest('uninstall.test.sh');
    }

    /** tests/scripts/install-sysbox.test.sh: --uninstall leaves none of the install behind. */
    public function test_the_sysbox_uninstaller_passes_its_own_checks(): void
    {
        $this->runShellTest('install-sysbox.test.sh');
    }

    private function runShellTest(string $name): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is needed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/tests/scripts/' . $name) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertMatchesRegularExpression('/\d+ passed, 0 failed/', implode("\n", $out));
    }
}
