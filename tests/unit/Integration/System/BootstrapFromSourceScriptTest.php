<?php

namespace Tests\Integration\System;

use PHPUnit\Framework\TestCase;

/** tests/scripts/bootstrap-from-source.test.sh: a source deploy reinstalls what the upload left out, and clears what it kept. */
class BootstrapFromSourceScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '' || trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('bash and openssl are needed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/tests/scripts/bootstrap-from-source.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('All passed.', implode("\n", $out));
    }
}
