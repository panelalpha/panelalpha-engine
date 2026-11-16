<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/** tests/scripts/installer-certificates.test.sh: an update serves the certificate for the name the engine already has. */
class InstallerCertificatesScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not installed');
        }

        exec(escapeshellarg($bash).' '.escapeshellarg(dirname(__DIR__, 4).'/tests/scripts/installer-certificates.test.sh').' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString(' passed, 0 failed', implode("\n", $out));
    }
}
