<?php

namespace Tests\Unit\System;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * docker-compose.yml-webserver is copied once with `cp -n`, so a new webserver
 * image tag never reached an installed host until this script refreshed it.
 */
class RefreshWebserverImageScriptTest extends TestCase
{
    public function test_the_script_passes_its_own_checks(): void
    {
        $script = dirname(__DIR__, 4) . '/scripts/refresh-webserver-image.test.sh';
        $process = Process::fromShellCommandline('bash ' . escapeshellarg($script));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString(' 0 failed', $process->getOutput());
    }
}
