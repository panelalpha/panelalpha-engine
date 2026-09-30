<?php

namespace Tests\Unit\Integrations\Storage;

use App\Integrations\Storage\BackupStorage;
use App\Integrations\Storage\Ftp;
use App\Integrations\Storage\Local;
use App\Integrations\Storage\S3;
use App\Models\BackupContainer;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class BackupContainerStorageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir() . '/pa-backup-factory-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_storage_returns_local_for_local_driver(): void
    {
        $container = $this->container('local', $this->root);

        $storage = $container->storage();

        $this->assertInstanceOf(Local::class, $storage);
    }

    public function test_storage_throws_invalid_argument_for_unknown_driver(): void
    {
        $container = $this->container('rclone', '/tmp');

        $this->expectException(InvalidArgumentException::class);
        $container->storage();
    }

    public function test_storage_returns_s3_for_s3_driver(): void
    {
        $container = $this->container('s3', 'my-bucket', [
            'access_key_id' => 'AKIATEST',
            'secret_access_key' => 'secret',
            'region' => 'eu-west-1',
        ]);

        $storage = $container->storage();

        $this->assertInstanceOf(S3::class, $storage);
    }

    // The FTP adapter reads ext-ftp constants; the core image has it, a bare PHP may not.
    #[RequiresPhpExtension('ftp')]
    public function test_storage_returns_ftp_for_ftp_ftps_and_sftp_drivers(): void
    {
        $credentials = [
            'host' => 'ftp.example.com',
            'username' => 'backup',
            'password' => 'secret',
        ];

        foreach (['ftp', 'ftps', 'sftp'] as $driver) {
            $container = $this->container($driver, '/backups', $credentials);
            $storage = $container->storage();
            $this->assertInstanceOf(Ftp::class, $storage, "Expected Ftp for {$driver}");
        }
    }

    public function test_s3_storage_accepts_filesystem_operator_for_tests(): void
    {
        $filesystem = $this->localFilesystem();
        $storage = new S3($filesystem);

        $this->assertInstanceOf(BackupStorage::class, $storage);
        $this->assertPutReadRoundTrip($storage, 'prefix/alice/1/files.tar.gz', 'payload');
    }

    public function test_s3_storage_does_not_double_prefix_object_keys(): void
    {
        $filesystem = $this->localFilesystem();
        $storage = new S3($filesystem);

        $key = 'backups/alice/1/files.tar.gz';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'x');
        rewind($stream);
        $storage->put($key, $stream);
        fclose($stream);

        $this->assertFileExists($this->root . '/' . $key);
        $this->assertFileDoesNotExist($this->root . '/backups/backups/alice/1/files.tar.gz');
    }

    public function test_ftp_storage_delete_prefix_only_removes_matching_keys(): void
    {
        $filesystem = $this->localFilesystem();
        $storage = new Ftp($filesystem);

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

    public function test_remote_storage_test_writes_and_removes_probe(): void
    {
        foreach ([S3::class, Ftp::class] as $class) {
            $filesystem = $this->localFilesystem();
            /** @var BackupStorage $storage */
            $storage = new $class($filesystem);

            $storage->test();

            $this->assertFalse($filesystem->fileExists('.panelalpha-backup-probe'), $class);
        }
    }

    private function container(string $driver, string $location, ?array $credentials = null): BackupContainer
    {
        $container = new BackupContainer();
        $container->driver = $driver;
        $container->location = $location;
        $container->credentials = $credentials;

        return $container;
    }

    private function localFilesystem(): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter($this->root));
    }

    private function assertPutReadRoundTrip(BackupStorage $storage, string $key, string $payload): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $payload);
        rewind($stream);
        $storage->put($key, $stream);
        fclose($stream);

        $this->assertTrue($storage->exists($key));
        $this->assertSame(strlen($payload), $storage->size($key));

        $read = $storage->readStream($key);
        $this->assertIsResource($read);
        $this->assertSame($payload, stream_get_contents($read));
        fclose($read);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
