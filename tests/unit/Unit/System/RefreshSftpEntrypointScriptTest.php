<?php

namespace Tests\Unit\System;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The sftp container runs its bind-mounted entry point once, at start, so a
 * release that changes that file reaches nothing until the container is
 * recreated; the update and a source deploy recreate it when it changed.
 */
class RefreshSftpEntrypointScriptTest extends TestCase
{
    private function repo(string $path): string
    {
        $full = dirname(base_path()) . '/' . $path;
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    public function test_the_script_passes_its_own_checks(): void
    {
        $script = dirname(__DIR__, 4) . '/tests/scripts/refresh-sftp-entrypoint.test.sh';
        $process = Process::fromShellCommandline('bash ' . escapeshellarg($script));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString(' 0 failed', $process->getOutput());
    }

    public function test_the_update_recreates_the_service_after_copying_the_entry_point(): void
    {
        $this->assertRunsAfterTheCopy(
            $this->repo('scripts/installer.sh'),
            'cp /opt/panelalpha/shared-hosting/templates/config/sftp/{entrypoint.sh,sync-logins.sh}',
            'scripts/refresh-sftp-entrypoint.sh /opt/panelalpha/shared-hosting'
        );
    }

    public function test_a_source_deploy_recreates_the_service_after_copying_the_entry_point(): void
    {
        $this->assertRunsAfterTheCopy(
            $this->repo('scripts/bootstrap-from-source.sh'),
            'cp templates/config/sftp/{entrypoint.sh,sync-logins.sh} config/sftp/',
            'scripts/refresh-sftp-entrypoint.sh'
        );
    }

    private function assertRunsAfterTheCopy(string $script, string $copy, string $refresh): void
    {
        $copied = strpos($script, $copy);
        $ran = strpos($script, $refresh);

        $this->assertIsInt($copied, 'the entry point is copied');
        $this->assertIsInt($ran, 'the service is recreated');
        $this->assertGreaterThan($copied, $ran, 'the recreate follows the copy');
    }
}
