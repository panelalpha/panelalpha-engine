<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/** scripts/installer-dropped-files.test.sh: an update removes what the new release dropped, and nothing else. */
class InstallerDroppedFilesScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '' || trim((string) shell_exec('command -v git')) === '') {
            $this->markTestSkipped('bash and git are needed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/scripts/installer-dropped-files.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString(' passed, 0 failed', implode("\n", $out));
    }
}
