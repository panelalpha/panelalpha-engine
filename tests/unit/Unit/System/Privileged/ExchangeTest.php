<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged\Exchange;
use PHPUnit\Framework\TestCase;

class ExchangeTest extends TestCase
{
    private string $dir;

    private string|false $before;

    protected function setUp(): void
    {
        $this->before = getenv('PA_EXCHANGE_DIR');
        $this->dir = sys_get_temp_dir().'/pa-exchange-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0711);
        putenv('PA_EXCHANGE_DIR='.$this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->before === false ? 'PA_EXCHANGE_DIR' : 'PA_EXCHANGE_DIR='.$this->before);
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function test_a_file_is_made_in_the_exchange_directory_for_core_alone(): void
    {
        $file = Exchange::file('pa-put-');
        $this->assertSame(realpath($this->dir), dirname($file));
        $this->assertStringStartsWith('pa-put-', basename($file));
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertTrue(Exchange::holds($file));
    }

    public function test_a_file_holding_contents_has_them_whole(): void
    {
        $file = Exchange::fileHolding('pa-put-', "pm = static\n");

        $this->assertSame("pm = static\n", file_get_contents($file));
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function test_contents_that_cannot_be_written_leave_no_file(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes a 0400 file, so the write never fails');
        }
        $umask = umask(0277);
        try {
            Exchange::fileHolding('pa-put-', 'pm = static');
            $this->fail('contents that were not written were handed out');
        } catch (\RuntimeException $e) {
            $this->assertSame('Could not write a file in '.$this->dir.'.', $e->getMessage());
        } finally {
            umask($umask);
        }
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_without_the_directory_no_file_falls_back_to_tmp(): void
    {
        putenv('PA_EXCHANGE_DIR='.$this->dir.'/missing');
        $this->expectException(\RuntimeException::class);
        Exchange::file('tmp_');
    }

    public function test_only_a_file_directly_in_it_is_held(): void
    {
        $outside = (string) tempnam(sys_get_temp_dir(), 'pa-upload-');
        mkdir($this->dir.'/nested');
        touch($this->dir.'/nested/a');
        try {
            $this->assertFalse(Exchange::holds($outside));
            $this->assertFalse(Exchange::holds($this->dir.'/nested/a'));
            $this->assertFalse(Exchange::holds($this->dir.'/../'.basename($outside)));
            $this->assertFalse(Exchange::holds($this->dir.'/missing'));
        } finally {
            @unlink($outside);
            @unlink($this->dir.'/nested/a');
            @rmdir($this->dir.'/nested');
        }
    }

    public function test_the_default_is_the_shared_volume(): void
    {
        putenv('PA_EXCHANGE_DIR');
        $this->assertSame('/var/lib/panelalpha/exchange', Exchange::directory());
    }
}
