<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\LogStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Writing under storage/logs/deploy, including when the directory belongs to
 * somebody else.
 *
 * The engine runs as www-data through the panel and as root through the CLI,
 * so a directory one created is not necessarily writable by the other; every
 * write retries once after a chown. And the pointer file is replaced rather
 * than rewritten, because the panel polls it while a deploy is still writing.
 *
 * The retry paths are not exercised here: each shells out to `sudo chown`
 * before trying again, so reaching them from a unit test needs a sudo rule on
 * the developer's machine. What is covered is everything up to that point.
 */
class LogStorageTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-storage-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_directory_is_created_when_it_is_missing(): void
    {
        LogStorage::ensureDirectory($this->dir . '/nested');

        $this->assertDirectoryExists($this->dir . '/nested');
    }

    public function test_an_existing_writable_directory_is_left_alone(): void
    {
        mkdir($this->dir, 0775, true);
        LogStorage::ensureDirectory($this->dir);

        $this->assertDirectoryExists($this->dir);
    }

    public function test_a_write_creates_the_directory_it_needs(): void
    {
        LogStorage::write($this->dir . '/deploy.log', "line\n");

        $this->assertSame("line\n", file_get_contents($this->dir . '/deploy.log'));
    }

    public function test_appending_adds_rather_than_replaces(): void
    {
        // How every log line after the first one is written.
        $path = $this->dir . '/deploy.log';
        LogStorage::write($path, "first\n", FILE_APPEND);
        LogStorage::write($path, "second\n", FILE_APPEND);

        $this->assertSame("first\nsecond\n", file_get_contents($path));
    }

    public function test_a_write_without_the_append_flag_replaces(): void
    {
        $path = $this->dir . '/deploy.log';
        LogStorage::write($path, "first\n");
        LogStorage::write($path, "second\n");

        $this->assertSame("second\n", file_get_contents($path));
    }

    public function test_a_replacement_leaves_no_temporary_file_behind(): void
    {
        // The temporary is renamed into place, so a poller either sees the
        // old file or the new one - never a half-written one, and never a
        // directory littered with .tmp files.
        $path = $this->dir . '/latest.json';
        LogStorage::replace($path, '{"status":"running"}');
        LogStorage::replace($path, '{"status":"success"}');

        $this->assertSame('{"status":"success"}', file_get_contents($path));
        $this->assertSame([], glob($this->dir . '/*.tmp.*') ?: []);
    }

    public function test_a_replacement_creates_the_file_when_it_is_missing(): void
    {
        LogStorage::replace($this->dir . '/latest.json', '{}');

        $this->assertFileExists($this->dir . '/latest.json');
    }
}
