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

    /** Swapped by another process, as the account would: PHP's own unlink would clear the cache. */
    public function test_a_directory_swapped_for_a_link_out_is_refused_in_the_same_process(): void
    {
        $home = $this->dir . '/acme';
        $this->assertTrue(Ftp::staysInside($home, 'public_html', $home . '/public_html'));

        exec('rm -rf ' . escapeshellarg($home . '/public_html') . ' && ln -s ' . escapeshellarg($this->dir . '/victim') . ' ' . escapeshellarg($home . '/public_html'));

        $this->assertFalse(Ftp::staysInside($home, 'public_html', $home . '/public_html'));
    }
}
