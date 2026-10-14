<?php

namespace Tests\Unit\Integrations\Storage;

use App\Integrations\Storage\S3;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;
use Tests\Unit\System\Project\Backup\FakeStorage;

class StorageDeletePrefixIsolationTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-backup-prefix-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_s3_delete_prefix_with_trailing_slash_does_not_delete_sibling_ids(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $storage = new S3($filesystem);

        foreach (['alice/1/files.tar.gz', 'alice/10/files.tar.gz', 'alice/11/files.tar.gz'] as $key) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, basename($key));
            rewind($stream);
            $storage->put($key, $stream);
            fclose($stream);
        }

        $storage->deletePrefix('alice/1/');

        $this->assertFalse($storage->exists('alice/1/files.tar.gz'));
        $this->assertTrue($storage->exists('alice/10/files.tar.gz'));
        $this->assertTrue($storage->exists('alice/11/files.tar.gz'));
    }

    public function test_fake_storage_prefix_with_trailing_slash_isolates_sibling_ids(): void
    {
        $storage = new FakeStorage();
        $storage->objects = [
            'alice/1/files.tar.gz' => 'one',
            'alice/10/files.tar.gz' => 'ten',
        ];

        $storage->deletePrefix('alice/1/');

        $this->assertArrayNotHasKey('alice/1/files.tar.gz', $storage->objects);
        $this->assertArrayHasKey('alice/10/files.tar.gz', $storage->objects);
    }
}
