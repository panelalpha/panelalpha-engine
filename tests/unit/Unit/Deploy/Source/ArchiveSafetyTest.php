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
        // free of "..", so only the link's target gives the escape away.
        $listing = "-rw-r--r-- root/root  12 2024-01-01 00:00 project/index.php\n"
            . "lrwxrwxrwx root/root   0 2024-01-01 00:00 link -> /etc\n";
        $names = ['project/index.php', 'link'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/symbolic link/');

        ArchiveSafety::assertRegularMembersOnly($listing);
        ArchiveSafety::assertLinksStayInside(ArchiveSafety::tarLinks($listing, $names), $names);
    }

    /** GitHub's archive of manyfold: `CLAUDE.md -> AGENTS.md` refused the whole deploy. */
    public function test_a_relative_link_inside_the_archive_is_allowed(): void
    {
        $listing = "drwxrwxr-x root/root   0 2025-09-30 10:00 manyfold-6e69e99/\n"
            . "-rw-rw-r-- root/root 812 2025-09-30 10:00 manyfold-6e69e99/AGENTS.md\n"
            . "lrwxrwxrwx root/root   0 2025-09-30 10:00 manyfold-6e69e99/CLAUDE.md -> AGENTS.md\n"
            . "lrwxrwxrwx root/root   0 2025-09-30 10:00 manyfold-6e69e99/docs/my readme -> ../AGENTS.md\n";
        $names = ['manyfold-6e69e99/', 'manyfold-6e69e99/AGENTS.md', 'manyfold-6e69e99/CLAUDE.md', 'manyfold-6e69e99/docs/my readme'];

        ArchiveSafety::assertRegularMembersOnly($listing);
        $links = ArchiveSafety::tarLinks($listing, $names);
        ArchiveSafety::assertLinksStayInside($links, $names);

        $this->assertSame([
            'manyfold-6e69e99/CLAUDE.md' => 'AGENTS.md',
            'manyfold-6e69e99/docs/my readme' => '../AGENTS.md',
        ], $links);
    }

    /** @return array<string, array{array<string, string>, list<string>}> */
    public static function escapingLinkProvider(): array
    {
        return [
            'absolute' => [['x' => '/etc'], ['x']],
            'empty' => [['x' => ''], ['x']],
            'climbs above the root' => [['x' => '../../etc'], ['x']],
            'climbs above the root from a subdirectory' => [['a/x' => '../../etc'], ['a/', 'a/x']],
            'through another link' => [['b' => '.', 'a' => 'b/..'], ['b', 'a']],
            'a member stored below a link' => [['l' => 'sub'], ['sub/', 'l', 'l/f']],
            'a backslash in the target' => [['l' => 'a\\b/../..'], ['l']],
            'a backslash in the link name' => [['a\\l' => '../x'], ['a\\l']],
        ];
    }

    /**
     * @param array<string, string> $links
     * @param list<string> $names
     */
    #[DataProvider('escapingLinkProvider')]
    public function test_a_link_that_could_leave_the_archive_is_refused(array $links, array $names): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains a symbolic link, which could redirect extraction outside the project directory.');

        ArchiveSafety::assertLinksStayInside($links, $names);
    }

    public function test_a_link_to_a_sibling_in_a_parent_directory_is_allowed(): void
    {
        ArchiveSafety::assertLinksStayInside(['docs/r' => '../README.md', 'bin/python' => 'python3'], ['README.md', 'docs/', 'docs/r', 'bin/python', 'bin/python3']);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function nameUnzipRenamesProvider(): array
    {
        return [
            'byte 0xff' => ["\xff/l"],
            'byte 0xff inside a component' => ["a\xffb/x"],
            'byte 0x7f' => ["\x7f/l"],
            'a raw control byte' => ["a\tb"],
            'a control byte as unzip lists it' => ['^A/l'],
            'a VMS version' => ['d/a;1'],
            'a bare trailing semicolon' => ['x;'],
        ];
    }

    #[DataProvider('nameUnzipRenamesProvider')]
    public function test_a_zip_name_unzip_would_rename_is_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains a file name unzip would rename on extraction');

        ArchiveSafety::assertUnzipKeepsNames(['index.php', $name]);
    }

    public function test_a_zip_name_unzip_writes_as_listed_is_kept(): void
    {
        ArchiveSafety::assertUnzipKeepsNames(["za\u{17c}\u{f3}\u{142}\u{107}/", "\x80\xfe/x", 'w;1/', 'w;1/q', 'z;1a', 'a^b', '^.txt', 'sp /x ']);

        $this->addToAssertionCount(1);
    }

    /** GNU tar's `escape` style, as core's C locale prints it. */
    public function test_tar_names_are_read_back_to_the_bytes_tar_writes(): void
    {
        $this->assertSame(
            ['ä/x', "nl\nx", 'b\\s', "t\tz", "del\x7f", 'sp ace', 'x€/'],
            ArchiveSafety::unescapeTarNames(['\\303\\244/x', 'nl\\nx', 'b\\\\s', 't\\tz', 'del\\177', 'sp ace', 'x\\342\\202\\254/'])
        );
    }

    #[DataProvider('unreadableTarNameProvider')]
    public function test_a_tar_name_with_an_escape_tar_does_not_write_is_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains an invalid path.');

        ArchiveSafety::unescapeTarNames([$name]);
    }

    /** @return array<string, array{string}> */
    public static function unreadableTarNameProvider(): array
    {
        return [
            'unknown letter' => ['a\\qb'],
            'octal above a byte' => ['a\\477'],
            'trailing backslash' => ['a\\'],
        ];
    }

    public function test_a_tar_member_starting_with_a_non_ascii_character_is_relative(): void
    {
        ArchiveSafety::assertSafeNames(ArchiveSafety::unescapeTarNames(['\\303\\244/', '\\303\\244/x', '\\303\\244bout.html']), backslashSeparates: false);

        $this->addToAssertionCount(1);
    }

    public function test_a_backslash_in_a_tar_name_is_part_of_the_name(): void
    {
        ArchiveSafety::assertSafeNames(['\\x', '..\\..\\x'], backslashSeparates: false);

        $this->addToAssertionCount(1);
    }

    /** `x€` is one directory, not the four `x\342\202\254` would make of it. */
    public function test_a_tar_link_climbing_out_past_a_non_ascii_directory_is_refused(): void
    {
        $listing = "drwxr-xr-x root/root 0 2026-10-26 12:00 x\\342\\202\\254/\n"
            . "lrwxrwxrwx root/root 0 2026-10-26 12:00 l -> x\\342\\202\\254/../../../..\n";
        $listed = ['x\\342\\202\\254/', 'l'];
        $links = ArchiveSafety::tarLinks($listing, $listed);

        $this->assertSame(['l' => 'x€/../../../..'], $links);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        ArchiveSafety::assertLinksStayInside($links, ArchiveSafety::unescapeTarNames($listed));
    }

    public function test_a_tar_link_named_and_pointing_in_non_ascii_inside_the_archive_is_allowed(): void
    {
        $listing = "-rw-r--r-- root/root 1 2026-10-26 12:00 \\303\\244/index.html\n"
            . "lrwxrwxrwx root/root 0 2026-10-26 12:00 \\303\\244/l\\303\\266 -> ../\\303\\244/index.html\n";
        $listed = ['\\303\\244/index.html', '\\303\\244/l\\303\\266'];
        $links = ArchiveSafety::tarLinks($listing, $listed);

        ArchiveSafety::assertLinksStayInside($links, ArchiveSafety::unescapeTarNames($listed));

        $this->assertSame(['ä/lö' => '../ä/index.html'], $links);
    }

    public function test_a_non_ascii_tar_link_climbing_above_the_root_is_refused(): void
    {
        $listing = "lrwxrwxrwx root/root 0 2026-10-26 12:00 \\303\\244/l -> ../../etc\n";
        $listed = ['\\303\\244/', '\\303\\244/l'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        ArchiveSafety::assertLinksStayInside(ArchiveSafety::tarLinks($listing, $listed), ArchiveSafety::unescapeTarNames($listed));
    }

    /** `a\b/../..` climbs one level above the root: the kernel never splits on `\`. */
    public function test_a_tar_link_target_holding_a_backslash_is_refused(): void
    {
        $listing = "lrwxrwxrwx root/root 0 2026-10-26 12:00 l -> a\\\\b/../..\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        ArchiveSafety::assertLinksStayInside(ArchiveSafety::tarLinks($listing, ['l']), ['l']);
    }

    public function test_zip_link_members_are_found_by_name(): void
    {
        $listing = "Archive:  upload.zip\n"
            . "-rw-r--r--  3.0 unx   12 tx defN 24-Jan-01 00:00 app/AGENTS.md\n"
            . "lrwxrwxrwx  3.0 unx    9 bx stor 24-Jan-01 00:00 app/my CLAUDE.md\n";

        $this->assertSame(['app/my CLAUDE.md'], ArchiveSafety::zipLinkNames($listing, ['app/AGENTS.md', 'app/my CLAUDE.md', 'CLAUDE.md']));
    }

    public function test_a_link_line_that_cannot_be_read_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ArchiveSafety::tarLinks("lrwxrwxrwx root/root 0 2024-01-01 00:00 other -> x\n", ['link']);
    }

    public function test_accepts_zip_members_without_file_type_bits(): void
    {
        // What zipfile.writestr() writes for a bare file name; unzip extracts it
        // as a regular file.
        ArchiveSafety::assertRegularMembersOnly(<<<'LISTING'
        Archive:  bare.zip
        Zip file size: 315 bytes, number of entries: 2
        ?rw-------  2.0 unx       65 b- stor 26-Oct-02 11:42 docker-compose.yml
        ?rw-------  2.0 unx       12 b- stor 26-Oct-02 11:42 sub/index.html
        2 files, 77 bytes uncompressed, 77 bytes compressed:  0.0%
        LISTING);

        $this->addToAssertionCount(1);
    }

    public function test_untyped_members_do_not_hide_a_symlink(): void
    {
        $listing = "?rw-------  2.0 unx   65 b- stor 26-Oct-02 11:42 docker-compose.yml\n"
            . "lrwxrwxrwx  3.0 unx    4 bx stor 24-Jan-01 00:00 link\n";

        $this->assertSame(['link'], ArchiveSafety::zipLinkNames($listing, ['docker-compose.yml', 'link']));
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
