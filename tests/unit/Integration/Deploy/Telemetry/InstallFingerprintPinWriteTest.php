<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\InstallFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * A pin that cannot be written is reported as not written and leaves no temporary file behind.
 */
class InstallFingerprintPinWriteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-pin-write-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function id(): string
    {
        return str_repeat('a', 32);
    }

    public function test_a_temporary_file_that_cannot_be_written_fails_the_pin(): void
    {
        $path = $this->dir.'/install-id';
        mkdir($path.'.tmp.'.getmypid());

        $this->assertFalse(InstallFingerprint::writePin($this->id(), $path));
        $this->assertNull(InstallFingerprint::readPin($path));
    }

    public function test_a_pin_that_cannot_replace_its_target_fails_and_cleans_up(): void
    {
        $path = $this->dir.'/install-id';
        mkdir($path);

        $this->assertFalse(InstallFingerprint::writePin($this->id(), $path));
        $this->assertSame([], glob($path.'.tmp.*'));
        $this->assertNull(InstallFingerprint::readPin($path));
    }
}
