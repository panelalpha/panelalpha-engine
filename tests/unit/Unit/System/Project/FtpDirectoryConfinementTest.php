<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Ftp;
use PHPUnit\Framework\TestCase;

/** The FTP container mounts every home, so an account's directory must resolve inside its own. */
class FtpDirectoryConfinementTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-ftp-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/acme/public_html', 0777, true);
        mkdir($this->dir . '/victim', 0777, true);
        symlink($this->dir . '/victim', $this->dir . '/acme/victim-link');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_directories(): void
    {
        $home = $this->dir . '/acme';
        $check = fn (string $d): bool => Ftp::staysInside($home, $d, $home . '/' . $d);

        $this->assertTrue($check(''));
        $this->assertTrue($check('public_html'));
        $this->assertFalse($check('../victim'));
        $this->assertFalse($check('public_html/../../victim'));
        $this->assertFalse($check('victim-link'));
        $this->assertFalse($check('missing'));
    }
}
