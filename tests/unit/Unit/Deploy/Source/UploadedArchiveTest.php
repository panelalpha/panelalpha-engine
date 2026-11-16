<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ArchiveSafety;
use App\Lib\Deploy\Source\UploadedArchive;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InfoZip;
use Tests\Support\LocalRoot;

/**
 * What an account is allowed to hand the extractor.
 *
 * Every rule here runs before a single byte is unpacked, and each one closes
 * a way of reaching a file the account does not own: a relative path climbing
 * out with `..`, a symlink pointing somewhere else, or an archive whose
 * extension does not match anything we know how to open.
 */
class UploadedArchiveTest extends TestCase
{
    /** Where a deploy stages its copy; the broker hands unzip that copy as /proc/self/fd/N. */
    private const STAGED = '/var/lib/panelalpha/archive-stage/ab/archive.zip';

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/pa-home-' . bin2hex(random_bytes(8));
        mkdir($this->home, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->home . '/*') as $entry) {
            is_dir((string) $entry) && !is_link((string) $entry)
                ? rmdir((string) $entry)
                : unlink((string) $entry);
        }
        if (is_dir($this->home)) {
            rmdir($this->home);
        }
        parent::tearDown();
    }

    /** The lookup as PHP itself would make it, for a home the test process can read. */
    private static function locate(string $path): ?string
    {
        return is_file($path) ? (realpath($path) ?: null) : null;
    }

    private function touchInHome(string $name): string
    {
        $path = $this->home . '/' . $name;
        file_put_contents($path, 'PK');

        return $path;
    }

    public function test_an_absolute_path_inside_the_home_is_accepted(): void
    {
        $path = $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome($path, $this->home, self::locate(...));

        $this->assertSame(realpath($path), $archive->path);
        $this->assertTrue($archive->isZip);
    }

    public function test_a_relative_path_is_resolved_against_the_home(): void
    {
        $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));

        $this->assertSame(realpath($this->home . '/site.zip'), $archive->path);
    }

    public function test_a_trailing_slash_on_the_home_changes_nothing(): void
    {
        $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome('site.zip', $this->home . '/', self::locate(...));

        $this->assertSame(realpath($this->home . '/site.zip'), $archive->path);
    }

    /**
     * ~/.panelalpha is the account's alone (0700): the engine's PHP, another user, sees
     * nothing there, so the file is looked up by the caller, as the account.
     */
    public function test_the_archive_is_looked_up_by_the_caller_not_by_php(): void
    {
        $asked = [];
        $locate = function (string $path) use (&$asked): string {
            $asked[] = $path;

            return $this->home . '/.panelalpha/archives/site.zip';
        };

        $archive = UploadedArchive::inHome('/.panelalpha/archives/site.zip', $this->home, $locate);

        $this->assertFileDoesNotExist($this->home . '/.panelalpha/archives/site.zip');
        $this->assertSame([$this->home . '/.panelalpha/archives/site.zip'], $asked);
        $this->assertSame($this->home . '/.panelalpha/archives/site.zip', $archive->path);
        $this->assertTrue($archive->isZip);
    }

    public function test_where_the_lookup_lands_must_be_inside_the_home(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the user home directory');

        UploadedArchive::inHome('escape.zip', $this->home, static fn (string $path): string => '/etc/site.zip');
    }

    public function test_a_missing_archive_is_named_in_the_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive not found');

        UploadedArchive::inHome('nothing-here.zip', $this->home, self::locate(...));
    }

    /**
     * The check is on where the path lands, not on how it is spelled — a
     * symlink is the way a spelling-based check gets walked around.
     */
    public function test_a_symlink_pointing_out_of_the_home_is_refused(): void
    {
        $outside = sys_get_temp_dir() . '/pa-outside-' . bin2hex(random_bytes(8)) . '.zip';
        file_put_contents($outside, 'PK');
        symlink($outside, $this->home . '/escape.zip');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('outside the user home directory');

            UploadedArchive::inHome('escape.zip', $this->home, self::locate(...));
        } finally {
            @unlink($outside);
        }
    }

    public function test_an_extension_we_cannot_open_is_refused(): void
    {
        $this->touchInHome('payload.php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported archive format');

        UploadedArchive::inHome('payload.php', $this->home, self::locate(...));
    }

    public function test_the_format_is_read_case_insensitively(): void
    {
        $this->touchInHome('SITE.ZIP');

        $this->assertTrue(UploadedArchive::inHome('SITE.ZIP', $this->home, self::locate(...))->isZip);
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function formats(): array
    {
        return [
            ['site.zip', true],
            ['site.tar.gz', false],
            ['site.tgz', false],
        ];
    }

    #[DataProvider('formats')]
    public function test_each_supported_format_stages_under_its_own_name(string $name, bool $isZip): void
    {
        $this->touchInHome($name);

        $archive = UploadedArchive::inHome($name, $this->home, self::locate(...));

        $this->assertSame($isZip, $archive->isZip);
        $this->assertSame($isZip ? 'archive.zip' : 'archive.tar.gz', $archive->stagedName());
    }

    public function test_a_zip_is_listed_and_extracted_with_the_zip_tools(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));

        $this->assertSame(['env', 'LC_ALL=C.UTF-8', 'unzip', '-Z1', '/staged'], $archive->listNamesArgv('/staged'));
        $this->assertSame(['env', 'LC_ALL=C.UTF-8', 'unzip', '-Z', '/staged'], $archive->listModesArgv('/staged'));
        $this->assertSame(['env', 'LC_ALL=C.UTF-8', 'unzip', '-p', '/staged', 'a/l'], $archive->readMemberArgv('/staged', 'a/l'));
        $extract = $archive->extractArgv(1001, 1001, '/staged', '/tmp/x');
        $this->assertSame([LocalRoot::CLIENT, 'account.extractZip', '--', '1001', '1001', '/staged', '/tmp/x'], $extract);
        $this->assertSame(['sh', '-c', LocalRoot::script($extract), 'sh', '/staged', '/tmp/x'], LocalRoot::plain($extract));
    }

    /** As PowerShell's Compress-Archive writes it: made on MS-DOS, `\` between the folders. */
    public function test_a_zip_made_on_windows_passes_the_checks_and_unpacks_into_its_folders(): void
    {
        $archive = $this->zipMadeOn(\ZipArchive::OPSYS_DOS, ['index.php' => '<?php require "inc/hello.php";', 'inc\\hello.php' => '<?php echo 1;']);
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $members = $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
            $extraction = new \Symfony\Component\Process\Process(LocalRoot::plain($archive->extractArgv(1, 1, $archive->path, $into)));
            $extraction->run();
            $archive->assertExtracted($extraction, $archive->path);

            $this->assertSame(['index.php', 'inc\\hello.php'], $members);
            $this->assertSame('<?php echo 1;', file_get_contents($into . '/inc/hello.php'));
            $this->assertSame(
                ['warning: site.zip appears to use backslashes as path separators'],
                $archive->extractionWarnings($extraction, $archive->path)
            );
        } finally {
            $this->runOk(['rm', '-rf', $into]);
        }
    }

    /** The real `unzip -Z` of a zip made on MS-DOS, a folder in it: every member's declared size counts. */
    public function test_the_listing_of_a_zip_made_on_windows_declares_every_members_size(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->home . '/site.zip', \ZipArchive::CREATE);
        $zip->addEmptyDir('inc');
        $zip->setExternalAttributesName('inc/', \ZipArchive::OPSYS_DOS, 0x10);
        foreach (['inc\\hello.php' => '<?php echo 1;', 'big.bin' => str_repeat("\0", 1000)] as $name => $contents) {
            $zip->addFromString($name, $contents);
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_DOS, 0x20);
        }
        $zip->close();
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $listing = $this->listInCLocale($archive->listModesArgv($archive->path));

        $this->assertMatchesRegularExpression('~^drwx--- +\d+\.\d+ fat +0 .* inc/$~m', $listing);
        ArchiveSafety::assertRegularMembersOnly($listing);
        ArchiveSafety::assertUncompressedSizeWithin($listing, 1013);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unpacks to more than/');
        ArchiveSafety::assertUncompressedSizeWithin($listing, 1012);
    }

    /** unzip extracts a member made on VMS with a link's mode as that link; `unzip -Z` lists it as `RWED,RWED,RWED`. */
    public function test_a_zip_member_listed_in_a_form_the_checks_cannot_read_is_refused(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->home . '/site.zip', \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php');
        $zip->addFromString('etc', '/etc');
        $zip->setExternalAttributesName('etc', \ZipArchive::OPSYS_OPENVMS, 0120777 << 16);
        $zip->close();
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive lists a member whose type or size cannot be read');

        $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
    }

    /** @return iterable<string, array{int, string}> */
    public static function backslashesUnzipKeeps(): iterable
    {
        yield 'made on Unix' => [\ZipArchive::OPSYS_UNIX, 'inc\\hello.php'];
        yield 'made on NTFS (ntf in unzip -Z)' => [11, 'inc\\hello.php'];
        yield 'made on MS-DOS, with a slash too' => [\ZipArchive::OPSYS_DOS, 'inc/sub\\hello.php'];
    }

    /** unzip writes these as one file named `inc\hello.php`, and `require 'inc/hello.php'` fails. */
    #[DataProvider('backslashesUnzipKeeps')]
    public function test_a_backslash_unzip_would_keep_in_a_file_name_is_refused(int $system, string $name): void
    {
        $archive = $this->zipMadeOn($system, ['index.php' => '<?php', $name => '<?php echo 1;']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive uses backslashes in file names, which would be extracted as part of each name rather than as folders; re-create it with forward slashes.');

        $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
    }

    public function test_a_zip_made_on_windows_climbing_out_with_backslashes_is_refused(): void
    {
        $archive = $this->zipMadeOn(\ZipArchive::OPSYS_DOS, ['index.php' => '<?php', '..\\..\\evil.php' => '<?php']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive contains a path outside the project directory.');

        $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
    }

    public function test_a_zip_unzip_cannot_read_still_fails_to_unpack(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $extraction = new \Symfony\Component\Process\Process(LocalRoot::plain($archive->extractArgv(1, 1, $archive->path, $into)));
            $extraction->run();

            $this->assertSame(9, $extraction->getExitCode(), $extraction->getErrorOutput());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not unpack site.zip: ');
            $archive->assertExtracted($extraction, $archive->path);
        } finally {
            $this->runOk(['rm', '-rf', $into]);
        }
    }

    /** A corrupt zip already fails its listing, before anything is extracted. */
    public function test_a_listing_that_fails_names_the_archive_not_the_staged_copy(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $zipinfo = static fn (array $argv): string => throw new \Exception('['.self::STAGED."]\n  End-of-central-directory signature not found.\nzipinfo:  cannot find zipfile directory in one of ".self::STAGED.' or '.self::STAGED.'.zip');

        try {
            $archive->assertSafeContents(self::STAGED, $zipinfo);
            $this->fail('A listing that failed was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not read site.zip: [site.zip] End-of-central-directory signature not found.', $e->getMessage());
            $this->assertStringNotContainsString('archive-stage', $e->getMessage());
        }
    }

    /** What the broker relays: unzip named the archive by the descriptor the broker opened it as. */
    public function test_a_warning_and_a_failure_name_the_archive_not_the_brokers_descriptor(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $warned = self::ended(0, "warning:  /proc/self/fd/7 appears to use backslashes as path separators\n");
        $failed = self::ended(9, "[/proc/self/fd/7]\n  End-of-central-directory signature not found.\n");

        $this->assertSame(['warning: site.zip appears to use backslashes as path separators'], $archive->extractionWarnings($warned, self::STAGED));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not unpack site.zip: [site.zip] End-of-central-directory signature not found.');
        $archive->assertExtracted($failed, self::STAGED);
    }

    /** @return iterable<string, array{string, bool, string, string}> */
    public static function failuresNamingTheStagedCopy(): iterable
    {
        yield 'zip' => ['site.zip', true, "[%s]\n  End-of-central-directory signature not found.\n", 'Could not unpack site.zip: [site.zip] End-of-central-directory signature not found.'];
        yield 'tar.gz' => ['site.tar.gz', false, "tar (child): %s: Cannot open: No such file or directory\ntar (child): Error is not recoverable: exiting now\n", 'Could not unpack site.tar.gz: tar (child): site.tar.gz: Cannot open: No such file or directory tar (child): Error is not recoverable: exiting now'];
        yield 'gz' => ['notes.gz', false, "gzip: %s: not in gzip format\n", 'Could not unpack notes.gz: gzip: notes.gz: not in gzip format'];
    }

    /** What the file manager relays: it hands unzip, tar and gzip its staged copy by path. */
    #[DataProvider('failuresNamingTheStagedCopy')]
    public function test_a_failure_names_the_archive_not_the_staged_copy_the_extractor_was_handed(string $name, bool $isZip, string $said, string $message): void
    {
        $staged = '/home/.panelalpha-unzip-stage/ab12/' . $name;
        $archive = UploadedArchive::confined('/home/alice/dir/' . $name, $isZip);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $archive->assertExtracted(self::ended(2, sprintf($said, $staged)), $staged);
    }

    /** What the file manager relays: it hands unzip its staged copy by path. */
    public function test_a_warning_names_the_archive_not_the_staged_copy_unzip_was_handed(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $warned = self::ended(0, "warning:  /home/.panelalpha-unzip-stage/ab12/site.zip appears to use backslashes as path separators\n");

        $this->assertSame(
            ['warning: site.zip appears to use backslashes as path separators'],
            $archive->extractionWarnings($warned, '/home/.panelalpha-unzip-stage/ab12/site.zip')
        );
    }

    /** GNU tar warns once per member about a macOS header it ignores, with nothing wrong. */
    public function test_a_tarball_extraction_logs_no_warnings(): void
    {
        $this->touchInHome('site.tar.gz');
        $archive = UploadedArchive::inHome('site.tar.gz', $this->home, self::locate(...));

        $this->assertSame([], $archive->extractionWarnings(self::ended(0, "tar: Ignoring unknown extended header keyword 'LIBARCHIVE.xattr.com.apple.provenance'\n"), self::STAGED));
    }

    private static function ended(int $code, string $stderr): \Symfony\Component\Process\Process
    {
        $process = new \Symfony\Component\Process\Process(['sh', '-c', 'printf %s "$1" >&2; exit "$0"', (string) $code, $stderr]);
        $process->run();

        return $process;
    }

    /** @param array<string, string> $members name => contents */
    private function zipMadeOn(int $system, array $members): UploadedArchive
    {
        $zip = new \ZipArchive();
        $zip->open($this->home . '/site.zip', \ZipArchive::CREATE);
        foreach ($members as $name => $contents) {
            $zip->addFromString($name, $contents);
            $zip->setExternalAttributesName($name, $system, $system === \ZipArchive::OPSYS_UNIX ? 0100644 << 16 : 0x20);
        }
        $zip->close();

        return UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
    }

    public function test_a_tarball_is_listed_and_extracted_with_the_tar_tools(): void
    {
        $this->touchInHome('site.tar.gz');
        $archive = UploadedArchive::inHome('site.tar.gz', $this->home, self::locate(...));

        $this->assertSame(['tar', '--quoting-style=escape', '-tzf', '/staged'], $archive->listNamesArgv('/staged'));
        $this->assertSame(['tar', '--quoting-style=escape', '-tvzf', '/staged'], $archive->listModesArgv('/staged'));
        $extract = $archive->extractArgv(1001, 1001, '/staged', '/tmp/x');
        $this->assertSame([LocalRoot::CLIENT, 'account.extractTar', '--', '1001', '1001', '/staged', '/tmp/x'], $extract);
        $this->assertSame(
            ['tar', '--no-same-owner', '--no-same-permissions', '-xzf', '/staged', '-C', '/tmp/x'],
            LocalRoot::plain($extract)
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function refusedReads(): iterable
    {
        yield 'zip, a relative staged path' => ['site.zip', 'names', 'archive.zip'];
        yield 'zip, an option as the staged path' => ['site.zip', 'modes', '-x'];
        yield 'zip, a member that is an option' => ['site.zip', 'member', '-d/etc'];
        yield 'zip, a member with a line break' => ['site.zip', 'member', "a\nb"];
        yield 'tar, an option as the staged path' => ['site.tar.gz', 'names', '--to-command=id'];
        yield 'tar, a relative staged path' => ['site.tar.gz', 'modes', 'a.tgz'];
    }

    #[DataProvider('refusedReads')]
    public function test_a_read_of_a_value_a_tool_could_misread_is_refused(string $name, string $read, string $value): void
    {
        $this->touchInHome($name);
        $archive = UploadedArchive::inHome($name, $this->home, self::locate(...));

        $this->expectException(InvalidArgumentException::class);
        match ($read) {
            'names' => $archive->listNamesArgv($value),
            'modes' => $archive->listModesArgv($value),
            'member' => $archive->readMemberArgv('/staged', $value),
        };
    }

    /**
     * An upload does not get to choose who owns what it unpacks to: a tar can
     * carry uid/gid and mode, and restoring them would let an archive drop a
     * setuid binary owned by root into the account.
     */
    public function test_extraction_never_restores_an_owner_or_a_mode(): void
    {
        $this->touchInHome('site.tar.gz');
        $argv = UploadedArchive::inHome('site.tar.gz', $this->home, self::locate(...))
            ->extractArgv(1001, 1001, '/staged', '/tmp/x');

        $this->assertContains('--no-same-owner', LocalRoot::plain($argv));
        $this->assertContains('--no-same-permissions', LocalRoot::plain($argv));
        $this->assertStringStartsWith('account.', (string) LocalRoot::action($argv));
        $this->assertSame(
            ['1001', '1001'],
            array_slice(LocalRoot::values($argv), 0, 2),
            'extraction runs as the account, by id: the core container has no passwd entry for it'
        );
    }

    /**
     * Python's zipfile.writestr() writes members as 0600 with no type bits.
     * Unpacked as-is, detection (another uid) could not read the compose
     * file and served the placeholder.
     */
    public function test_a_zip_of_0600_members_unpacks_with_checkout_modes(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->home . '/app.zip', \ZipArchive::CREATE);
        $zip->addFromString('docker-compose.yml', "services: {}\n");
        $zip->addFromString('bin/run', "#!/bin/sh\n");
        $zip->addEmptyDir('conf');
        $zip->addFromString('conf/app.ini', "a=1\n");
        $zip->setExternalAttributesName('docker-compose.yml', \ZipArchive::OPSYS_UNIX, 0600 << 16);
        $zip->setExternalAttributesName('bin/run', \ZipArchive::OPSYS_UNIX, 0700 << 16);
        $zip->setExternalAttributesName('conf/', \ZipArchive::OPSYS_UNIX, 040700 << 16);
        $zip->setExternalAttributesName('conf/app.ini', \ZipArchive::OPSYS_UNIX, 0100600 << 16);
        $zip->close();

        $this->assertSame(
            ['docker-compose.yml' => '0644', 'bin/run' => '0755', 'conf' => '0755', 'conf/app.ini' => '0644'],
            $this->unpackedModes('app.zip', ['docker-compose.yml', 'bin/run', 'conf', 'conf/app.ini'])
        );
    }

    public function test_a_tarball_of_0600_members_unpacks_with_checkout_modes(): void
    {
        $src = $this->home . '/src';
        mkdir($src . '/bin', 0700, true);
        file_put_contents($src . '/index.php', '<?php');
        chmod($src . '/index.php', 0600);
        file_put_contents($src . '/bin/run', '#!/bin/sh');
        chmod($src . '/bin/run', 0700);
        $this->runOk(['tar', '-czf', $this->home . '/app.tar.gz', '-C', $src, '.']);
        $this->runOk(['rm', '-rf', $src]);

        $this->assertSame(
            ['index.php' => '0644', 'bin' => '0755', 'bin/run' => '0755'],
            $this->unpackedModes('app.tar.gz', ['index.php', 'bin', 'bin/run'])
        );
    }

    /**
     * A zip member's Unicode Path extra field names it again, and may name it differently:
     * the listing the checks read shows that name, so extraction has to write it.
     */
    public function test_a_zip_member_is_written_under_the_name_its_listing_shows(): void
    {
        InfoZip::withUnicodePath($this->home . '/named.zip', 'evil/x', 'safe/x');
        $archive = UploadedArchive::inHome('named.zip', $this->home, self::locate(...));
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $listed = $this->runOk($archive->listNamesArgv($archive->path));
            $this->runOk(LocalRoot::plain($archive->extractArgv(1, 1, $archive->path, $into)));

            $this->assertSame("safe/x\n", $listed);
            $this->assertFileExists($into . '/safe/x');
            $this->assertDirectoryDoesNotExist($into . '/evil');
        } finally {
            $this->runOk(['rm', '-rf', $into, $this->home . '/named.zip']);
        }
    }

    /** @return array<string, array{callable(string, string): void}> */
    public static function infoZipNames(): array
    {
        return [
            'Unicode Path field' => [static fn (string $path, string $name) => InfoZip::withUnicodePath($path, $name, $name)],
            'UTF-8 flag' => [InfoZip::utf8Flagged(...)],
        ];
    }

    /**
     * How `zip -r` on Linux or macOS stores a non-ASCII name. In the C locale PHP-FPM runs in,
     * unzip would list and write it as `za#U017c...`; a UTF-8 one keeps the name.
     */
    #[DataProvider('infoZipNames')]
    public function test_a_non_ascii_info_zip_name_is_listed_and_written_as_it_is_in_the_c_locale(callable $zip): void
    {
        $name = "za\u{17c}\u{f3}\u{142}\u{107}/\u{105}.txt";
        $zip($this->home . '/site.zip', $name);
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $listed = $this->listInCLocale($archive->listNamesArgv($archive->path));
            $this->runInCLocale(LocalRoot::plain($archive->extractArgv(1, 1, $archive->path, $into)));

            $this->assertSame($name . "\n", $listed);
            $this->assertSame('x', file_get_contents($into . '/' . $name));
        } finally {
            $this->runOk(['rm', '-rf', $into, $this->home . '/site.zip']);
        }
    }

    /** @return array<string, array{array<string, string>, array<string, string>}> */
    public static function linksUnzipWritesElsewhere(): array
    {
        return [
            'byte 0xff' => [["\xff/l" => '../escape'], []],
            'byte 0x7f' => [["\x7f/l" => '../escape'], []],
            'control byte beside the ^A it is listed as' => [["\x01/l" => '../escape'], ['^A/l' => 'x']],
            'VMS version' => [['d/a;1' => '..', 'd/b' => 'a/../..'], []],
        ];
    }

    /**
     * Each link passes the checks under the name `unzip -Z1` lists, and unzip writes it under
     * another: `l -> ../escape` one level up, or `d/a -> ..` that `d/b` then climbs through.
     *
     * @param array<string, string> $links
     * @param array<string, string> $files
     */
    #[DataProvider('linksUnzipWritesElsewhere')]
    public function test_a_zip_link_unzip_would_write_under_another_name_is_refused(array $links, array $files): void
    {
        InfoZip::withLinks($this->home . '/site.zip', $links, $files);
        $archive = UploadedArchive::inHome('site.zip', $this->home, self::locate(...));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unzip would rename');

        $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
    }

    /** Core lists in the C locale, where tar prints `x€` as `x\342\202\254`. */
    public function test_a_tarball_link_climbing_out_past_a_non_ascii_directory_is_refused(): void
    {
        $src = $this->home . '/src';
        mkdir($src . '/x€', 0777, true);
        symlink('x€/../../../..', $src . '/l');
        $this->runOk(['tar', '-czf', $this->home . '/app.tar.gz', '-C', $src, 'x€', 'l']);
        $this->runOk(['rm', '-rf', $src]);
        $archive = UploadedArchive::inHome('app.tar.gz', $this->home, self::locate(...));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        $archive->assertSafeContents($archive->path, $this->listInCLocale(...));
    }

    public function test_a_tarball_whose_members_start_with_a_non_ascii_character_is_accepted(): void
    {
        $src = $this->home . '/src';
        mkdir($src . '/ä', 0777, true);
        file_put_contents($src . '/ä/x', 'x');
        file_put_contents($src . '/äbout.html', 'x');
        symlink('x', $src . '/ä/l');
        $this->runOk(['tar', '-czf', $this->home . '/app.tar.gz', '-C', $src, 'ä', 'äbout.html']);
        $this->runOk(['rm', '-rf', $src]);
        $archive = UploadedArchive::inHome('app.tar.gz', $this->home, self::locate(...));

        $members = $archive->assertSafeContents($archive->path, $this->listInCLocale(...));

        $this->assertEqualsCanonicalizing(['ä/', 'ä/x', 'ä/l', 'äbout.html'], $members);
    }

    public function test_modes_are_normalised_as_the_account(): void
    {
        $this->touchInHome('site.zip');

        $argv = UploadedArchive::inHome('site.zip', $this->home, self::locate(...))->normaliseModesArgv(1001, 1002, '/tmp/x');

        $this->assertSame([LocalRoot::CLIENT, 'account.normaliseModes', '--', '1001', '1002', '/tmp/x'], $argv);
        $this->assertSame(['chmod', '-R', 'u+rwX,go=rX', '--', '/tmp/x'], LocalRoot::plain($argv));
    }

    /**
     * Extract and normalise for real, as ourselves.
     *
     * @param list<string> $paths
     * @return array<string, string> path => octal mode
     */
    private function unpackedModes(string $archiveName, array $paths): array
    {
        $archive = UploadedArchive::inHome($archiveName, $this->home, self::locate(...));
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $this->runOk(LocalRoot::plain($archive->extractArgv(1, 1, $archive->path, $into)));
            $this->runOk(LocalRoot::plain($archive->normaliseModesArgv(1, 1, $into)));
            clearstatcache();
            $modes = [];
            foreach ($paths as $path) {
                $modes[$path] = sprintf('%04o', fileperms($into . '/' . $path) & 07777);
            }

            return $modes;
        } finally {
            $this->runOk(['rm', '-rf', $into, $this->home . '/' . $archiveName]);
        }
    }

    /** @param list<string> $argv */
    private function listInCLocale(array $argv): string
    {
        return $this->runInCLocale($argv);
    }

    /**
     * PHP-FPM's locale: it has no LANG or LC_*, so glibc runs its commands in C.
     *
     * @param list<string> $argv
     */
    private function runInCLocale(array $argv): string
    {
        $process = new \Symfony\Component\Process\Process($argv, null, ['LC_ALL' => 'C', 'LANG' => 'C']);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @param list<string> $argv */
    private function runOk(array $argv): string
    {
        $process = new \Symfony\Component\Process\Process($argv);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), implode(' ', $argv) . "\n" . $process->getErrorOutput());

        return $process->getOutput();
    }
}
