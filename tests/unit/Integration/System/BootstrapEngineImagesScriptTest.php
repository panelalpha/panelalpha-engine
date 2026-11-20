<?php

namespace Tests\Integration\System;

use PHPUnit\Framework\TestCase;

/** tests/scripts/bootstrap-engine-images.test.sh: a source deploy runs core and root-broker images built from the uploaded tree. */
class BootstrapEngineImagesScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '' || trim((string) shell_exec('command -v jq')) === '') {
            $this->markTestSkipped('bash and jq are needed');
        }

        exec(escapeshellarg($bash).' '.escapeshellarg(dirname(__DIR__, 4).'/tests/scripts/bootstrap-engine-images.test.sh').' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString(' passed, 0 failed', implode("\n", $out));
    }
}
