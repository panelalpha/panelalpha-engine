<?php

namespace Tests\Unit\Integrations\Storage;

use App\Integrations\Storage\Local;
use App\System\Privileged\Root;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\LocalRoot;

class LocalTest extends TestCase
{
    private string $root = '';

    private string $broker = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-backup-storage-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
        $this->broker = sys_get_temp_dir() . '/pa-backup-storage-broker-' . bin2hex(random_bytes(8));
        mkdir($this->broker, 0755, true);
        $this->installBroker();
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->removeDir($this->broker);
        $this->removeDir($this->root);
        parent::tearDown();
    }

    /** Logs each request to brokerLog(), then answers it with $body, by default pa-root-local. */
    private function installBroker(?string $body = null): void
    {
        $client = $this->broker . '/pa-root';
        $log = escapeshellarg($this->brokerLog());
        $body ??= 'exec ' . escapeshellarg(LocalRoot::client()) . ' "$@"';
        file_put_contents($client, "#!/bin/sh\necho \"\$@\" >> {$log}\n{$body}\n");
        chmod($client, 0755);
        Root::answerWith($client);
    }

    private function brokerLog(): string
    {
        return $this->broker . '/requests.log';
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

    public function test_delete_prefix_removes_the_objects_as_root(): void
    {
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');

        $storage->deletePrefix('alice/1/');

        $this->assertFalse($storage->exists('alice/1/a.bin'));
        $root = realpath($this->root);
        $this->assertSame(
            "backup-store.removeTree -- {$root}/alice/1\nbackup-store.removeEmptyParents -- {$root}/alice\n",
            (string) file_get_contents($this->brokerLog()),
        );
    }

    public function test_delete_prefix_fails_when_the_objects_cannot_be_removed(): void
    {
        $this->installBroker("echo 'rm: cannot remove: Operation not permitted' >&2\nexit 1");
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');

        try {
            $storage->deletePrefix('alice/1/');
            $this->fail('A prefix that could not be removed must fail the delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Operation not permitted', $e->getMessage());
        }

        $this->assertTrue($storage->exists('alice/1/a.bin'));
    }

    public function test_delete_prefix_fails_when_the_objects_are_still_there(): void
    {
        $this->installBroker('exit 0');
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');

        $this->expectException(RuntimeException::class);
        $storage->deletePrefix('alice/1/');
    }

    public function test_delete_prefix_refuses_the_whole_storage_root(): void
    {
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');

        try {
            $storage->deletePrefix('');
            $this->fail('An empty prefix must not remove the storage root.');
        } catch (InvalidArgumentException) {
        }

        $this->assertTrue($storage->exists('alice/1/a.bin'));
    }

    public function test_delete_prefix_removes_the_directories_it_left_empty(): void
    {
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');

        $storage->deletePrefix('alice/1/');

        $this->assertDirectoryDoesNotExist($this->root . '/alice');
        $this->assertDirectoryExists($this->root);
    }

    public function test_delete_prefix_keeps_a_parent_that_still_holds_backups(): void
    {
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');
        $this->putObject($storage, 'alice/2/b.bin');

        $storage->deletePrefix('alice/1/');

        $this->assertDirectoryDoesNotExist($this->root . '/alice/1');
        $this->assertTrue($storage->exists('alice/2/b.bin'));
    }

    public function test_an_empty_root_is_removed_with_the_empty_directories_in_it(): void
    {
        mkdir($this->root . '/alice/1', 0775, true);

        (new Local($this->root))->removeEmptyRoot();

        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_a_root_that_still_holds_a_file_is_kept(): void
    {
        $storage = new Local($this->root);
        $this->putObject($storage, 'alice/1/a.bin');
        mkdir($this->root . '/bob', 0775, true);

        $storage->removeEmptyRoot();

        $this->assertTrue($storage->exists('alice/1/a.bin'));
        $this->assertDirectoryExists($this->root . '/bob');
        $this->assertFileDoesNotExist($this->brokerLog());
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

    private function putObject(Local $storage, string $key): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'x');
        rewind($stream);
        $storage->put($key, $stream);
        fclose($stream);
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
