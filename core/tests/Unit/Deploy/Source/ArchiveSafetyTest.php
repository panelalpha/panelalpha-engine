<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArchiveSafetyTest extends TestCase
{
    public function test_accepts_relative_archive_entries(): void
    {
        ArchiveSafety::assertSafeListing("project/\nproject/public/index.php\nproject/assets/app.js\n");

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafePathProvider')]
    public function test_rejects_entries_outside_the_project(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ArchiveSafety::assertSafeListing($path . "\n");
    }

    public function test_accepts_verbose_listing_of_files_and_directories(): void
    {
        ArchiveSafety::assertRegularMembersOnly(<<<'LISTING'
        Archive:  upload.zip
        Zip file size: 1234 bytes, number of entries: 2
        drwxr-xr-x  3.0 unx        0 bx stor 24-Jan-01 00:00 project/
        -rw-r--r--  3.0 unx       12 tx defN 24-Jan-01 00:00 project/index.php
        2 files, 12 bytes uncompressed, 12 bytes compressed:  0.0%
        LISTING);

        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_symlink_that_name_checks_cannot_catch(): void
    {
        // `link -> /etc` followed by `link/passwd`: both names are relative and
        // free of "..", so only the member type gives the escape away.
        $listing = "-rw-r--r--  3.0 unx   12 tx defN 24-Jan-01 00:00 project/index.php\n"
            . "lrwxrwxrwx  3.0 unx    4 bx stor 24-Jan-01 00:00 link -> /etc\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/symbolic link/');

        ArchiveSafety::assertRegularMembersOnly($listing);
    }

    public function test_rejects_symlinks_in_tar_verbose_listing(): void
    {
        $listing = "-rw-r--r-- root/root  12 2024-01-01 00:00 project/index.php\n"
            . "lrwxrwxrwx root/root   0 2024-01-01 00:00 link -> /etc\n";

        $this->expectException(\InvalidArgumentException::class);

        ArchiveSafety::assertRegularMembersOnly($listing);
    }

    public function test_rejects_special_files(): void
    {
        $listing = "crw-rw-rw- root/root 0 2024-01-01 00:00 dev/null\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/special file/');

        ArchiveSafety::assertRegularMembersOnly($listing);
    }

    public function test_sums_uncompressed_sizes_from_a_zip_listing(): void
    {
        // `unzip -Z` writes version and os between the mode and the size, so a
        // parser that counts columns off the mode reads `3.0` as the size.
        $listing = <<<'LISTING'
        Archive:  upload.zip
        Zip file size: 720 bytes, number of entries: 2
        -rw-rw-r--  3.0 unx   100000 bx defN 26-Sep-22 13:46 big.bin
        -rw-rw-r--  3.0 unx        2 tx stor 26-Sep-22 13:46 small.txt
        2 files, 100002 bytes uncompressed, 128 bytes compressed:  99.9%
        LISTING;

        ArchiveSafety::assertUncompressedSizeWithin($listing, 100002);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unpacks to more than/');

        ArchiveSafety::assertUncompressedSizeWithin($listing, 100001);
    }

    public function test_sums_uncompressed_sizes_from_a_tar_listing(): void
    {
        // `tar -tvzf` writes owner/group there instead, and the directory row
        // carries a 0 that must not be mistaken for the whole archive.
        $listing = <<<'LISTING'
        -rw-rw-r-- miodek/miodek 100000 2026-09-22 13:46 big.bin
        drwxrwxr-x miodek/miodek      0 2026-09-22 13:46 sub/
        -rw-rw-r-- miodek/miodek      1 2026-09-22 13:46 sub/x.txt
        LISTING;

        ArchiveSafety::assertUncompressedSizeWithin($listing, 100001);

        $this->expectException(\InvalidArgumentException::class);

        ArchiveSafety::assertUncompressedSizeWithin($listing, 100000);
    }

    public function test_a_small_archive_of_enormous_members_is_refused(): void
    {
        // The zip-bomb shape the entry count never caught: four members.
        $listing = '';
        for ($i = 0; $i < 4; $i++) {
            $listing .= "-rw-r--r--  3.0 unx 2000000000 bx defN 26-Sep-22 13:46 zeros{$i}.bin\n";
        }

        $this->expectException(\InvalidArgumentException::class);

        ArchiveSafety::assertUncompressedSizeWithin($listing);
    }

    public function test_a_real_sized_upload_passes_the_default_limit(): void
    {
        $listing = "-rw-r--r-- root/root 524288000 2026-09-22 13:46 project/vendor.tar\n";

        ArchiveSafety::assertUncompressedSizeWithin($listing);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePathProvider(): array
    {
        return [
            'absolute' => ['/etc/passwd'],
            'parent' => ['project/../../etc/passwd'],
            'windows parent' => ['project\\..\\..\\windows\\system.ini'],
            'windows drive' => ['C:\\windows\\system.ini'],
        ];
    }
}
