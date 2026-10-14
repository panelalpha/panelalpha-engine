<?php

namespace Tests\Unit\Project;

use App\Lib\Project\AccountFile;
use PHPUnit\Framework\TestCase;

class AccountFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-account-file-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_regular_file_owned_by_the_account_is_returned(): void
    {
        $path = $this->dir . '/app.log';
        file_put_contents($path, "one\n");

        $stat = AccountFile::ownedRegular($path, posix_getuid());

        $this->assertNotNull($stat);
        $this->assertSame(4, $stat['size']);
        $this->assertSame(1, $stat['nlink']);
    }

    public function test_a_symlink_is_refused_even_when_it_points_at_an_owned_file(): void
    {
        file_put_contents($this->dir . '/outside.log', "secret\n");
        $link = $this->dir . '/app.log';
        symlink($this->dir . '/outside.log', $link);

        $this->assertNull(AccountFile::ownedRegular($link, posix_getuid()));
    }

    public function test_a_file_with_a_second_hard_link_is_refused(): void
    {
        $path = $this->dir . '/app.log';
        file_put_contents($path, "one\n");
        link($path, $this->dir . '/other.log');

        $this->assertNull(AccountFile::ownedRegular($path, posix_getuid()));
    }

    public function test_a_file_owned_by_another_account_is_refused(): void
    {
        $path = $this->dir . '/app.log';
        file_put_contents($path, "one\n");

        $this->assertNull(AccountFile::ownedRegular($path, posix_getuid() + 1));
        $this->assertNull(AccountFile::ownedRegular($path, null));
    }

    public function test_a_directory_and_a_missing_path_are_refused(): void
    {
        $this->assertNull(AccountFile::ownedRegular($this->dir, posix_getuid()));
        $this->assertNull(AccountFile::ownedRegular($this->dir . '/missing.log', posix_getuid()));
    }
}
