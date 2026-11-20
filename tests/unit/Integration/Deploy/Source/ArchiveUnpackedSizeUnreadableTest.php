<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use PHPUnit\Framework\TestCase;

/** Archives the counter cannot open, members it cannot read, and a ratio that gives a bomb away early. */
class ArchiveUnpackedSizeUnreadableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/archive-unreadable-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_a_file_that_is_no_zip_cannot_be_read(): void
    {
        $path = $this->dir.'/site.zip';
        file_put_contents($path, 'this is plain text, not a zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read the uploaded archive.');

        ArchiveUnpackedSize::assertWithin($path, true);
    }

    public function test_an_encrypted_member_has_no_stream_to_count(): void
    {
        $path = $this->dir.'/secret.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('secret.txt', str_repeat('x', 4096));
        $this->assertTrue($zip->setEncryptionName('secret.txt', \ZipArchive::EM_AES_256, 'password'));
        $zip->close();

        ArchiveUnpackedSize::assertWithin($path, true, 1024);

        $this->addToAssertionCount(1);
    }

    public function test_a_path_gzip_cannot_open_cannot_be_read(): void
    {
        $path = $this->dir.'/site.sock';
        $server = stream_socket_server('unix://'.$path);
        $this->assertIsResource($server);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not read the uploaded archive.');

            ArchiveUnpackedSize::assertWithin($path, false);
        } finally {
            fclose($server);
        }
    }

    public function test_a_gzip_far_past_its_own_size_is_refused_before_it_is_finished(): void
    {
        $path = $this->dir.'/bomb.tar.gz';
        $gz = gzopen($path, 'wb9');
        $megabyte = str_repeat("\0", 1024 * 1024);
        for ($i = 0; $i < 80; $i++) {
            gzwrite($gz, $megabyte);
        }
        gzclose($gz);
        $this->assertLessThan(1024 * 1024, filesize($path));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive unpacks to more than 5120 MB, which is more than this server accepts.');

        ArchiveUnpackedSize::assertWithin($path, false);
    }
}
