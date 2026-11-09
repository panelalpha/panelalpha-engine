<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArchiveSafetyTest extends TestCase
{
    private const DOS_LISTING = "Archive:  site.zip\nZip file size: 264 bytes, number of entries: 2\n"
        . "-rw-a--     2.0 fat       30 b- stor 80-Jan-01 00:00 index.php\n"
        . "-rw-a--     2.0 fat       16 b- stor 80-Jan-01 00:00 inc\\hello.php\n"
        . "2 files, 46 bytes uncompressed, 46 bytes compressed:  0.0%\n";

    /** `unzip -Z` of a zip made with python's zipfile as Windows tools make one: folders, attributes, a volume label, NTFS. */
    private const WINDOWS_LISTING = <<<'LISTING'
        Archive:  fixture.zip
        Zip file size: 1076 bytes, number of entries: 9
        drwx---     2.0 fat        0 b- stor 80-Jan-01 00:00 site\
        -rw-a--     2.0 fat       30 b- stor 80-Jan-01 00:00 site\index.php
        drwxa--     2.0 fat        0 b- stor 80-Jan-01 00:00 site\inc\
        -r--a--     2.0 fat       13 b- stor 80-Jan-01 00:00 site\inc\hello.php
        -rw-a--     2.0 fat   100000 b- defN 80-Jan-01 00:00 site\big.bin
        -rwxa--     2.0 fat        2 b- stor 80-Jan-01 00:00 site\run.exe
        -rw-ahs     2.0 fat        3 b- stor 80-Jan-01 00:00 site\hidden.ini
        Vrw----     2.0 fat        0 b- stor 80-Jan-01 00:00 DISK
        -rw-a--     2.0 ntf        4 b- stor 80-Jan-01 00:00 site\ntfs.txt
        9 files, 100052 bytes uncompressed, 166 bytes compressed:  99.8%
        LISTING;

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

    public function test_a_backslash_in_a_name_made_on_ms_dos_separates_folders(): void
    {
        ArchiveSafety::assertBackslashesSeparateFolders(self::DOS_LISTING, ['index.php', 'inc\\hello.php']);

        $this->addToAssertionCount(1);
    }

    /** The name is everything after the time, a leading space and a date-like part included. */
    public function test_a_name_made_on_ms_dos_is_read_whole(): void
    {
        $listing = "-rw-a--     6.3 fat        3 b- defN 80-Jan-01 00:00  lead\\x\n"
            . "-rw-a--     6.3 fat        3 b- defN 80-Jan-01 00:00 a 80-Jan-01 00:00 b\\c\n";

        ArchiveSafety::assertBackslashesSeparateFolders($listing, [' lead\\x', 'a 80-Jan-01 00:00 b\\c']);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function backslashUnzipKeepsProvider(): iterable
    {
        yield 'made on Unix' => ["-rw-r--r--  2.0 unx       16 b- stor 80-Jan-01 00:00 inc\\hello.php\n", ['inc\\hello.php']];
        yield 'made on NTFS' => ["-rw-a--     2.0 ntf       16 b- stor 80-Jan-01 00:00 inc\\hello.php\n", ['inc\\hello.php']];
        yield 'made on MS-DOS, with a slash too' => ["-rw-a--     2.0 fat        1 b- stor 80-Jan-01 00:00 a/b\\c.txt\n", ['a/b\\c.txt']];
        yield 'a second member of the name made on Unix' => [
            self::DOS_LISTING . "-rw-r--r--  2.0 unx       16 b- stor 80-Jan-01 00:00 inc\\hello.php\n",
            ['index.php', 'inc\\hello.php', 'inc\\hello.php'],
        ];
        yield 'a Unix member named like a DOS one after a space' => [
            self::DOS_LISTING . "-rw-r--r--  2.0 unx       16 b- stor 80-Jan-01 00:00 x inc\\hello.php\n",
            ['index.php', 'inc\\hello.php', 'x inc\\hello.php'],
        ];
        yield 'a line the attributes overrun' => ["RWED,RWED,RWED  vms        3 b- defN 80-Jan-01 00:00 vms\\x\n", ['vms\\x']];
        yield 'a name the listing does not show' => [self::DOS_LISTING, ['index.php', 'lib\\x.php']];
    }

    /** @param list<string> $names */
    #[DataProvider('backslashUnzipKeepsProvider')]
    public function test_a_backslash_unzip_writes_into_the_file_name_is_refused(string $listing, array $names): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive uses backslashes in file names, which would be extracted as part of each name rather than as folders; re-create it with forward slashes.');

        ArchiveSafety::assertBackslashesSeparateFolders($listing, $names);
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

    public function test_sums_the_sizes_of_members_made_on_ms_dos(): void
    {
        ArchiveSafety::assertUncompressedSizeWithin(self::WINDOWS_LISTING, 100052);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unpacks to more than/');

        ArchiveSafety::assertUncompressedSizeWithin(self::WINDOWS_LISTING, 100051);
    }

    /** A volume label (`V`) is skipped by unzip; a file or a folder made on MS-DOS is never a link or a device. */
    public function test_accepts_the_files_folders_and_volume_label_of_a_zip_made_on_ms_dos(): void
    {
        ArchiveSafety::assertRegularMembersOnly(self::WINDOWS_LISTING);

        $this->addToAssertionCount(1);
    }

    /** The header names the archive by a path that can hold anything, the totals count bytes; neither is a member. */
    public function test_the_header_and_totals_of_a_zip_listing_are_not_members(): void
    {
        $listing = "Archive:  /home/.panelalpha-unzip-stage/ab12/my 99999999 b- stor 80-Jan-01 00:00 x.zip\n"
            . "Zip file size: 1076 bytes, number of entries: 1\n"
            . "-rw-a--     2.0 fat       30 b- stor 80-Jan-01 00:00 index.php\n"
            . "1 file, 30 bytes uncompressed, 30 bytes compressed:  0.0%\n";

        ArchiveSafety::assertRegularMembersOnly($listing);
        ArchiveSafety::assertUncompressedSizeWithin($listing, 30);

        $this->addToAssertionCount(1);
    }

    /**
     * As `unzip -Z` lists members made on other systems; unzip extracts the VMS ones as symlinks to `/etc`.
     *
     * @return iterable<string, array{string}>
     */
    public static function unreadableZipMemberProvider(): iterable
    {
        yield 'a link made on VMS' => ['RWED,RWED,RWED  vms        4 b- stor 80-Jan-01 00:00 evil'];
        yield 'a link made on VMS, owner only' => ['RWD,,       2.0 vms        4 b- stor 80-Jan-01 00:00 evil2'];
        yield 'made on an Amiga' => ['?hsparwed   2.0 ami        4 b- stor 80-Jan-01 00:00 evil'];
        yield 'made on THEOS' => ['KHM..E...   2.0 ths        4 b- stor 80-Jan-01 00:00 evil'];
    }

    #[DataProvider('unreadableZipMemberProvider')]
    public function test_a_zip_member_neither_form_reads_is_refused_by_the_member_check(string $line): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive lists a member whose type or size cannot be read');

        ArchiveSafety::assertRegularMembersOnly("Archive:  site.zip\n{$line}\n");
    }

    #[DataProvider('unreadableZipMemberProvider')]
    public function test_a_zip_member_neither_form_reads_is_refused_by_the_size_check(string $line): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive lists a member whose type or size cannot be read');

        ArchiveSafety::assertUncompressedSizeWithin("Archive:  site.zip\n{$line}\n");
    }

    public function test_a_member_whose_size_cannot_be_read_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive lists a member whose type or size cannot be read');

        ArchiveSafety::assertUncompressedSizeWithin("crw-rw-rw- root/root 1,3 2024-01-01 00:00 dev/null\n");
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
