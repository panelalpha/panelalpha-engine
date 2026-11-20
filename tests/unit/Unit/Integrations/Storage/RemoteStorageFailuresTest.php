<?php

namespace Tests\Unit\Integrations\Storage;

use App\Integrations\Storage\AbstractStorage;
use App\Integrations\Storage\Ftp;
use App\Integrations\Storage\S3;
use InvalidArgumentException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The FTP and S3 backup stores turn a driver's refusal into the engine's own errors, and a
 * file that is already gone into nothing at all.
 */
class RemoteStorageFailuresTest extends TestCase
{
    /** @return array<string, array{class-string<AbstractStorage>}> */
    public static function stores(): array
    {
        return ['ftp' => [Ftp::class], 's3' => [S3::class]];
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_a_refused_write_names_the_key(string $store): void
    {
        $filesystem = $this->filesystem();
        $filesystem->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('alice/1/a.bin', 'disk full'));

        try {
            (new $store($filesystem))->put('alice/1/a.bin', fopen('php://memory', 'r'));
            $this->fail('a refused write must raise');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not write backup object: alice/1/a.bin', $e->getMessage());
            $this->assertInstanceOf(UnableToWriteFile::class, $e->getPrevious());
        }
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_an_unreadable_object_reads_as_not_found(string $store): void
    {
        $filesystem = $this->filesystem();
        $filesystem->method('readStream')->willThrowException(UnableToReadFile::fromLocation('alice/1/a.bin'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup object not found: alice/1/a.bin');

        (new $store($filesystem))->readStream('alice/1/a.bin');
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_a_size_the_driver_cannot_tell_reads_as_not_found(string $store): void
    {
        $filesystem = $this->filesystem();
        $filesystem->method('fileSize')->willThrowException(UnableToRetrieveMetadata::fileSize('alice/1/a.bin'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup object not found: alice/1/a.bin');

        (new $store($filesystem))->size('alice/1/a.bin');
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_deleting_an_object_that_cannot_be_deleted_is_quiet(string $store): void
    {
        $filesystem = $this->expecting();
        $filesystem->expects($this->once())->method('delete')->with('alice/1/a.bin')
            ->willThrowException(UnableToDeleteFile::atLocation('alice/1/a.bin'));

        (new $store($filesystem))->delete('/alice/1/a.bin');
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_an_empty_prefix_lists_nothing(string $store): void
    {
        $filesystem = $this->expecting();
        $filesystem->expects($this->never())->method('listContents');
        $filesystem->expects($this->never())->method('delete');

        (new $store($filesystem))->deletePrefix('/');
    }

    /** @param class-string<Ftp|S3> $store */
    #[DataProvider('stores')]
    public function test_an_empty_key_is_refused(string $store): void
    {
        $filesystem = $this->expecting();
        $filesystem->expects($this->never())->method('fileExists');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid key.');

        (new $store($filesystem))->exists('./');
    }

    public function test_an_s3_prefix_deletes_only_its_files(): void
    {
        $filesystem = $this->expecting();
        $filesystem->expects($this->once())->method('listContents')->with('alice/1', true)->willReturn(new DirectoryListing([
            new DirectoryAttributes('alice/1/logs'),
            new FileAttributes('alice/1/a.bin'),
            new FileAttributes('alice/1/logs/b.log'),
        ]));
        $deleted = [];
        $filesystem->method('delete')->willReturnCallback(function (string $path) use (&$deleted): void {
            $deleted[] = $path;
        });

        (new S3($filesystem))->deletePrefix('alice/1/');

        $this->assertSame(['alice/1/a.bin', 'alice/1/logs/b.log'], $deleted);
    }

    public function test_an_ftp_prefix_removes_its_files_then_every_directory_left_empty_deepest_first(): void
    {
        $filesystem = $this->filesystem();
        $removed = [];
        $filesystem->method('listContents')->willReturnCallback(
            fn (string $path, bool $deep): DirectoryListing => new DirectoryListing(match (true) {
                $deep => [
                    new DirectoryAttributes('alice/1/logs'),
                    new FileAttributes('alice/1/logs/b.log'),
                    new DirectoryAttributes('alice/1/logs/old'),
                ],
                $path === 'alice' => [new DirectoryAttributes('alice/2')],
                $path === 'alice/1/logs' => throw UnableToListContents::atLocation($path, false, new \RuntimeException('550')),
                default => [],
            })
        );
        $filesystem->method('delete')->willReturnCallback(function (string $path) use (&$removed): void {
            $removed[] = "file {$path}";
        });
        $filesystem->method('deleteDirectory')->willReturnCallback(function (string $path) use (&$removed): void {
            if ($path === 'alice/1/logs/old') {
                throw UnableToDeleteDirectory::atLocation($path, 'busy');
            }
            $removed[] = "dir {$path}";
        });

        (new Ftp($filesystem))->deletePrefix('alice/1');

        $this->assertSame(['file alice/1/logs/b.log', 'dir alice/1'], $removed);
    }

    private function filesystem(): FilesystemOperator&Stub
    {
        return $this->createStub(FilesystemOperator::class);
    }

    private function expecting(): FilesystemOperator&MockObject
    {
        return $this->createMock(FilesystemOperator::class);
    }
}
