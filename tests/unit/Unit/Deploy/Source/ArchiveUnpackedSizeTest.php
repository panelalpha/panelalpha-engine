<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use PHPUnit\Framework\TestCase;

class ArchiveUnpackedSizeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/archive-size-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_a_zip_that_under_declares_its_size_is_still_caught(): void
    {
        // The whole reason this class exists. A zip states each member's
        // uncompressed size in the central directory and again in the local
        // header, both written by whoever built it, and neither unzip nor
        // ZipArchive checks them against the data.
        $path = $this->zipOfZeros(8 * 1024 * 1024);
        $this->declareSize($path, 10);

        $zip = new \ZipArchive();
        $zip->open($path);
        self::assertSame(10, $zip->statIndex(0)['size'], 'the archive should be lying');
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unpacks to more than/');

        ArchiveUnpackedSize::assertWithin($path, true, 1024 * 1024);
    }

    public function test_an_honest_zip_within_the_cap_passes(): void
    {
        $path = $this->zipOfZeros(256 * 1024);

        ArchiveUnpackedSize::assertWithin($path, true, 1024 * 1024);

        $this->addToAssertionCount(1);
    }

    public function test_a_zip_over_the_cap_is_refused(): void
    {
        $path = $this->zipOfZeros(4 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);

        ArchiveUnpackedSize::assertWithin($path, true, 1024 * 1024);
    }

    public function test_a_tar_gz_is_measured_from_its_decompressed_stream(): void
    {
        $path = $this->tarGzOfZeros(4 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);

        ArchiveUnpackedSize::assertWithin($path, false, 1024 * 1024);
    }

    public function test_an_honest_tar_gz_within_the_cap_passes(): void
    {
        $path = $this->tarGzOfZeros(128 * 1024);

        ArchiveUnpackedSize::assertWithin($path, false, 1024 * 1024);

        $this->addToAssertionCount(1);
    }

    public function test_an_unreadable_archive_is_an_error_not_a_pass(): void
    {
        $this->expectException(\RuntimeException::class);

        ArchiveUnpackedSize::assertWithin($this->dir . '/nothing-here.zip', true, 1024);
    }

    public function test_a_zip_with_a_directory_entry_is_measured_not_skipped(): void
    {
        $path = $this->dir . '/withdir.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addEmptyDir('sub');
        $zip->addFromString('sub/zeros.bin', str_repeat("\0", 4 * 1024 * 1024));
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);

        ArchiveUnpackedSize::assertWithin($path, true, 1024 * 1024);
    }

    private function zipOfZeros(int $bytes): string
    {
        $path = $this->dir . '/a.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('zeros.bin', str_repeat("\0", $bytes));
        $zip->close();

        return $path;
    }

    private function tarGzOfZeros(int $bytes): string
    {
        $path = $this->dir . '/a.tar.gz';
        $member = str_repeat("\0", $bytes);

        // A tar header is 512 bytes of mostly fixed-width fields; only the
        // name, mode, sizes and checksum matter for a stream this is only
        // going to be measured, not unpacked.
        $header = str_pad('zeros.bin', 100, "\0");
        $header .= str_pad('0000644', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad(sprintf('%011o', $bytes), 12, "\0");
        $header .= str_pad(sprintf('%011o', 0), 12, "\0");
        $header .= '        ';
        $header .= '0';
        $header = str_pad($header, 512, "\0");
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, str_pad(sprintf('%06o', $checksum) . "\0 ", 8, "\0"), 148, 8);

        $padding = (512 - ($bytes % 512)) % 512;
        $tar = $header . $member . str_repeat("\0", $padding) . str_repeat("\0", 1024);

        file_put_contents($path, gzencode($tar, 9));

        return $path;
    }

    /**
     * Overwrite the uncompressed-size field in both places a zip records it.
     */
    private function declareSize(string $path, int $size): void
    {
        $raw = file_get_contents($path);

        $central = strpos($raw, "PK\x01\x02");
        self::assertNotFalse($central, 'no central directory in the fixture');
        $raw = substr_replace($raw, pack('V', $size), $central + 24, 4);

        $local = strpos($raw, "PK\x03\x04");
        self::assertNotFalse($local, 'no local file header in the fixture');
        $raw = substr_replace($raw, pack('V', $size), $local + 22, 4);

        file_put_contents($path, $raw);
    }
}
