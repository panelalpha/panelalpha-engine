<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;

class VolumeArchiveVolumeScriptTest extends TestCase
{
    public function test_restore_script_is_ash_compatible(): void
    {
        $script = VolumeArchive::volumeRestoreScript();

        $this->assertStringNotContainsString('shopt', $script);
        $this->assertStringContainsString('.panelalpha-aside', $script);
        $this->assertStringContainsString('leftover .panelalpha-aside exists', $script);

        $tmp = tempnam(sys_get_temp_dir(), 'pa-vol-restore-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_rollback_script_is_ash_compatible(): void
    {
        $script = VolumeArchive::volumeRollbackScript();

        $this->assertStringNotContainsString('shopt', $script);
        $this->assertStringContainsString('.panelalpha-aside', $script);

        $tmp = tempnam(sys_get_temp_dir(), 'pa-vol-rollback-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
