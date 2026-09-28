<?php

namespace Tests\Unit\Helpers;

use App\Lib\Helpers\FileStreamWrapper;
use PHPUnit\Framework\TestCase;

/**
 * The file download helper runs as root and follows symlinks. Given a root
 * directory it must refuse anything that resolves outside it, however the
 * path was spelled.
 *
 * The helper is exercised directly (no sudo) so this runs anywhere.
 */
class FileStreamConfinementTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-stream-' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/home/acme/public_html', 0777, true);
        mkdir($this->dir . '/home/victim', 0777, true);
        mkdir($this->dir . '/etc', 0777, true);
        file_put_contents($this->dir . '/home/acme/public_html/index.php', 'hello');
        file_put_contents($this->dir . '/home/victim/secret.txt', 'theirs');
        file_put_contents($this->dir . '/etc/shadow', 'root:*:');
        symlink($this->dir . '/etc/shadow', $this->dir . '/home/acme/shadow-link');
        symlink($this->dir . '/home/victim', $this->dir . '/home/acme/victim-link');
        symlink('public_html/index.php', $this->dir . '/home/acme/own-link');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_reads_a_file_inside_the_root(): void
    {
        [$code, $out] = $this->open($this->dir . '/home/acme/public_html/index.php');

        $this->assertSame(0, $code);
        $this->assertSame('hello', $out);
    }

    public function test_follows_a_symlink_that_stays_inside_the_root(): void
    {
        [$code, $out] = $this->open($this->dir . '/home/acme/own-link');

        $this->assertSame(0, $code);
        $this->assertSame('hello', $out);
    }

    public function test_refuses_a_symlink_to_a_system_file(): void
    {
        [$code, $out, $err] = $this->open($this->dir . '/home/acme/shadow-link');

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('does not resolve to a file under', $err);
    }

    public function test_refuses_a_symlinked_directory_into_another_account(): void
    {
        [$code, $out] = $this->open($this->dir . '/home/acme/victim-link/secret.txt');

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
    }

    public function test_refuses_a_parent_traversal_even_when_the_string_looks_confined(): void
    {
        [$code] = $this->open($this->dir . '/home/acme/../victim/secret.txt');

        $this->assertSame(1, $code);
    }

    public function test_is_unconfined_without_a_root(): void
    {
        [$code, $out] = $this->open($this->dir . '/home/acme/shadow-link', root: null);

        $this->assertSame(0, $code);
        $this->assertSame('root:*:', $out);
    }

    public function test_the_wrapper_side_check_agrees(): void
    {
        $root = $this->dir . '/home/acme';

        $this->assertTrue(FileStreamWrapper::isUnder($root, $root . '/own-link'));
        $this->assertFalse(FileStreamWrapper::isUnder($root, $root . '/shadow-link'));
        $this->assertFalse(FileStreamWrapper::isUnder($root, $root . '/victim-link/secret.txt'));
        $this->assertFalse(FileStreamWrapper::isUnder($root, $root . '/../victim/secret.txt'));
        $this->assertFalse(FileStreamWrapper::isUnder($root, $root));
    }

    /**
     * Drive the helper the way the wrapper does: open, read, close.
     *
     * @return array{int, string, string} exit code, file contents, stderr
     */
    private function open(string $path, ?string $root = 'default'): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__, 3) . '/app/Lib/Helpers/file_stream.php', 'r', $path];
        if ($root === 'default') {
            $cmd[] = $this->dir . '/home/acme';
        } elseif ($root !== null) {
            $cmd[] = $root;
        }

        $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        fwrite($pipes[0], "READ 4096\nCLOSE\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $contents = '';
        if (preg_match('/^OK ([0-9a-f]+)\n/', $stdout, $m)) {
            $contents = substr($stdout, strlen($m[0]), hexdec($m[1]));
        }

        return [$code, $contents, $stderr];
    }
}
