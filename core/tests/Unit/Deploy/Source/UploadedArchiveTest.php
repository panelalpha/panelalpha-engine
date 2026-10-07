<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\UploadedArchive;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    private function touchInHome(string $name): string
    {
        $path = $this->home . '/' . $name;
        file_put_contents($path, 'PK');

        return $path;
    }

    public function test_an_absolute_path_inside_the_home_is_accepted(): void
    {
        $path = $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome($path, $this->home);

        $this->assertSame(realpath($path), $archive->path);
        $this->assertTrue($archive->isZip);
    }

    public function test_a_relative_path_is_resolved_against_the_home(): void
    {
        $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome('site.zip', $this->home);

        $this->assertSame(realpath($this->home . '/site.zip'), $archive->path);
    }

    public function test_a_trailing_slash_on_the_home_changes_nothing(): void
    {
        $this->touchInHome('site.zip');

        $archive = UploadedArchive::inHome('site.zip', $this->home . '/');

        $this->assertSame(realpath($this->home . '/site.zip'), $archive->path);
    }

    public function test_a_missing_archive_is_named_in_the_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive not found');

        UploadedArchive::inHome('nothing-here.zip', $this->home);
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

            UploadedArchive::inHome('escape.zip', $this->home);
        } finally {
            @unlink($outside);
        }
    }

    public function test_an_extension_we_cannot_open_is_refused(): void
    {
        $this->touchInHome('payload.php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported archive format');

        UploadedArchive::inHome('payload.php', $this->home);
    }

    public function test_the_format_is_read_case_insensitively(): void
    {
        $this->touchInHome('SITE.ZIP');

        $this->assertTrue(UploadedArchive::inHome('SITE.ZIP', $this->home)->isZip);
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

        $archive = UploadedArchive::inHome($name, $this->home);

        $this->assertSame($isZip, $archive->isZip);
        $this->assertSame($isZip ? 'archive.zip' : 'archive.tar.gz', $archive->stagedName());
    }

    public function test_a_zip_is_listed_and_extracted_with_the_zip_tools(): void
    {
        $this->touchInHome('site.zip');
        $archive = UploadedArchive::inHome('site.zip', $this->home);

        $this->assertSame(['sudo', 'unzip', '-Z1', '/staged'], $archive->listNamesArgv('/staged'));
        $this->assertSame(['sudo', 'unzip', '-Z', '/staged'], $archive->listModesArgv('/staged'));
        $this->assertSame(
            [
                'sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups',
                'unzip', '-UU', '-o', '/staged', '-d', '/tmp/x',
            ],
            $archive->extractArgv(1001, 1001, '/staged', '/tmp/x')
        );
    }

    public function test_a_tarball_is_listed_and_extracted_with_the_tar_tools(): void
    {
        $this->touchInHome('site.tar.gz');
        $archive = UploadedArchive::inHome('site.tar.gz', $this->home);

        $this->assertSame(['sudo', 'tar', '-tzf', '/staged'], $archive->listNamesArgv('/staged'));
        $this->assertSame(['sudo', 'tar', '-tvzf', '/staged'], $archive->listModesArgv('/staged'));
        $this->assertSame(
            [
                'sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups', 'tar',
                '--no-same-owner', '--no-same-permissions', '-xzf', '/staged', '-C', '/tmp/x',
            ],
            $archive->extractArgv(1001, 1001, '/staged', '/tmp/x')
        );
    }

    /**
     * An upload does not get to choose who owns what it unpacks to: a tar can
     * carry uid/gid and mode, and restoring them would let an archive drop a
     * setuid binary owned by root into the account.
     */
    public function test_extraction_never_restores_an_owner_or_a_mode(): void
    {
        $this->touchInHome('site.tar.gz');
        $argv = UploadedArchive::inHome('site.tar.gz', $this->home)
            ->extractArgv(1001, 1001, '/staged', '/tmp/x');

        $this->assertContains('--no-same-owner', $argv);
        $this->assertContains('--no-same-permissions', $argv);
        $this->assertSame(
            ['sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups'],
            array_slice($argv, 0, 7),
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

    public function test_modes_are_normalised_as_the_account(): void
    {
        $this->touchInHome('site.zip');

        $this->assertSame(
            ['sudo', 'setpriv', '--reuid', '1001', '--regid', '1002', '--clear-groups', 'chmod', '-R', 'u+rwX,go=rX', '--', '/tmp/x'],
            UploadedArchive::inHome('site.zip', $this->home)->normaliseModesArgv(1001, 1002, '/tmp/x')
        );
    }

    /**
     * Extract and normalise for real, as ourselves (the sudo setpriv prefix dropped).
     *
     * @param list<string> $paths
     * @return array<string, string> path => octal mode
     */
    private function unpackedModes(string $archiveName, array $paths): array
    {
        $archive = UploadedArchive::inHome($archiveName, $this->home);
        $into = sys_get_temp_dir() . '/pa-unpack-' . bin2hex(random_bytes(8));
        mkdir($into);
        try {
            $this->runOk(array_slice($archive->extractArgv(1, 1, $archive->path, $into), 7));
            $this->runOk(array_slice($archive->normaliseModesArgv(1, 1, $into), 7));
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
    private function runOk(array $argv): void
    {
        $process = new \Symfony\Component\Process\Process($argv);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), implode(' ', $argv) . "\n" . $process->getErrorOutput());
    }
}
