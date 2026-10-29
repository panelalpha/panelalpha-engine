<?php

namespace Tests\Unit\System;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * config/modsecurity is host state seeded from the shipped templates: nothing is
 * overwritten, a switched-off rule stays off, and files that differ are listed.
 */
class SeedModsecurityScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $script = dirname(__DIR__, 4) . '/tests/scripts/seed-modsecurity.test.sh';
        $process = Process::fromShellCommandline('bash ' . escapeshellarg($script));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString(' 0 failed', $process->getOutput());
    }
}
