<?php

namespace Tests\Integration\System;

use PHPUnit\Framework\TestCase;

/** tests/scripts/installer-engine-images.test.sh: an update has the new core and root-broker images before the copy. */
class InstallerEngineImagesScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '' || trim((string) shell_exec('command -v jq')) === '') {
            $this->markTestSkipped('bash and jq are needed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/tests/scripts/installer-engine-images.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString(' passed, 0 failed', implode("\n", $out));
    }
}
