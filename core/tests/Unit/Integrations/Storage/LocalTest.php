<?php

namespace Tests\Unit\Integrations\Storage;

use App\Integrations\Storage\Local;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LocalTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-backup-storage-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_put_exists_size_and_read_stream(): void
    {
        $storage = new Local($this->root);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'backup-payload');
        rewind($stream);

        $storage->put('alice/1/files.tar.gz', $stream);
        fclose($stream);

        $this->assertTrue($storage->exists('alice/1/files.tar.gz'));
        $this->assertSame(14, $storage->size('alice/1/files.tar.gz'));

        $read = $storage->readStream('alice/1/files.tar.gz');
        $this->assertIsResource($read);
        $this->assertSame('backup-payload', stream_get_contents($read));
        fclose($read);
    }

    public function test_put_creates_parent_directories(): void
    {
        $storage = new Local($this->root);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'x');
        rewind($stream);

        $storage->put('deep/nested/key.bin', $stream);
        fclose($stream);

        $this->assertFileExists($this->root . '/deep/nested/key.bin');
    }

    public function test_delete_removes_a_single_object(): void
    {
        $storage = new Local($this->root);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'x');
        rewind($stream);
        $storage->put('one/file.bin', $stream);
        fclose($stream);

        $storage->delete('one/file.bin');

        $this->assertFalse($storage->exists('one/file.bin'));
    }

    public function test_delete_prefix_only_removes_matching_keys(): void
    {
        $storage = new Local($this->root);
        foreach (['alice/1/a.bin', 'alice/1/b.bin', 'alice/2/c.bin', 'bob/1/d.bin'] as $key) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, 'x');
            rewind($stream);
            $storage->put($key, $stream);
            fclose($stream);
        }

        $storage->deletePrefix('alice/1/');

        $this->assertFalse($storage->exists('alice/1/a.bin'));
        $this->assertFalse($storage->exists('alice/1/b.bin'));
        $this->assertTrue($storage->exists('alice/2/c.bin'));
        $this->assertTrue($storage->exists('bob/1/d.bin'));
    }

    public function test_test_succeeds_on_writable_root(): void
    {
        $storage = new Local($this->root);

        $storage->test();

        $this->assertFileDoesNotExist($this->root . '/.panelalpha-backup-probe');
    }

    public function test_test_creates_a_missing_root(): void
    {
        // test() prepares the root the way the first put() would, so a new location passes.
        $missing = $this->root . '/missing';
        $storage = new Local($missing);

        $storage->test();

        $this->assertDirectoryExists($missing);
        $this->assertFileDoesNotExist($missing . '/.panelalpha-backup-probe');
    }

    public function test_rejects_path_traversal(): void
    {
        $storage = new Local($this->root);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'x');
        rewind($stream);

        $this->expectException(InvalidArgumentException::class);
        $storage->put('../escape.bin', $stream);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
