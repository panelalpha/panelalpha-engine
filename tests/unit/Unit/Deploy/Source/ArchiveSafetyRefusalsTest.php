<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveSafety;
use PHPUnit\Framework\TestCase;

/** Listings and names the archive checks refuse outright. */
class ArchiveSafetyRefusalsTest extends TestCase
{
    private const LINK_MESSAGE = 'Archive contains a symbolic link, which could redirect extraction outside the project directory.';

    public function test_an_archive_with_more_than_a_hundred_thousand_entries_is_refused(): void
    {
        $names = array_map(static fn (int $i): string => "f{$i}.txt", range(0, 100000));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains too many entries.');

        ArchiveSafety::assertSafeNames($names);
    }

    public function test_a_hundred_thousand_entries_are_still_accepted(): void
    {
        $names = array_map(static fn (int $i): string => "f{$i}.txt", range(1, 100000));

        ArchiveSafety::assertSafeNames($names);

        $this->addToAssertionCount(1);
    }

    public function test_a_path_with_a_nul_byte_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains an invalid path.');

        ArchiveSafety::assertSafePath("site/index.html\0.php");
    }

    public function test_a_tar_link_line_without_a_date_is_refused(): void
    {
        $listing = "lrwxrwxrwx user/group 0 yesterday link -> target\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::LINK_MESSAGE);

        ArchiveSafety::tarLinks($listing, ['link']);
    }

    public function test_a_readable_tar_link_line_is_read(): void
    {
        $listing = "lrwxrwxrwx user/group 0 2024-01-02 03:04 site/link -> ../target\n";

        $this->assertSame(['site/link' => '../target'], ArchiveSafety::tarLinks($listing, ['site/link']));
    }

    public function test_a_zip_link_line_naming_no_listed_member_is_refused(): void
    {
        $listing = "lrwxrwxrwx  3.0 unx       9 bx stor 24-Jan-02 03:04 elsewhere\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::LINK_MESSAGE);

        ArchiveSafety::zipLinkNames($listing, ['site/index.html']);
    }

    public function test_a_zip_link_line_takes_the_longest_member_it_ends_with(): void
    {
        $listing = "lrwxrwxrwx  3.0 unx       9 bx stor 24-Jan-02 03:04 site/a link\n";

        $this->assertSame(['site/a link'], ArchiveSafety::zipLinkNames($listing, ['link', 'site/a link']));
    }
}
