<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallSourcePluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\TestNetwork;
use ZipArchive;

/**
 * Tag-archive fixture → install zip with wordpress-migrator/ root, no network.
 * Only the release pinned by its SHA-256 is repacked; its version says whether
 * it must require the token.
 */
class WordpressMigratorZipTest extends WordpressMigrationTestCase
{
    public function test_repack_puts_plugin_under_wordpress_migrator_and_excludes_branding(): void
    {
        $fixture = $this->buildTagArchiveFixture([
            'wordpress-migrator.php' => "<?php\n// plugin\n",
            'config.php' => "<?php\nreturn ['token' => 'abc', 'mode' => 'source'];\n",
            'restore.php' => "<?php\n// restore\n",
            'branding.php' => "<?php\n// must not ship\n",
            'assets/logo.png' => 'fake-png',
        ]);

        $installZip = null;
        try {
            $installZip = (new WordpressMigratorZip())->repackFromTagArchive($fixture);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($installZip) === true);

            $this->assertNotFalse($zip->locateName('wordpress-migrator/wordpress-migrator.php'));
            $this->assertNotFalse($zip->locateName('wordpress-migrator/config.php'));
            $this->assertNotFalse($zip->locateName('wordpress-migrator/restore.php'));
            $this->assertFalse($zip->locateName('wordpress-migrator/branding.php'));
            $this->assertFalse($zip->locateName('branding.php'));
            $this->assertNotFalse($zip->locateName('wordpress-migrator/assets/logo.png'));

            // Tag wrapper directory must not appear at the install zip root.
            $this->assertFalse($zip->locateName('panelalpha-wordpress-migrator-1.1.4/wordpress-migrator.php'));

            $zip->close();
        } finally {
            @unlink($fixture);
            if (is_string($installZip) && is_file($installZip)) {
                $workDir = dirname($installZip);
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getPathname());
                    } else {
                        @unlink($file->getPathname());
                    }
                }
                @rmdir($workDir);
            }
        }
    }

    public function test_discard_removes_the_migrator_temp_directory(): void
    {
        $workDir = sys_get_temp_dir() . '/wp-migrator-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0700, true);
        $zipPath = $workDir . '/wordpress-migrator-install.zip';
        file_put_contents($zipPath, 'zip');

        $cache = new \ReflectionProperty(WordpressMigratorZip::class, 'cache');
        $cache->setValue(null, [41 => $zipPath]);

        WordpressMigratorZip::discard(41);

        $this->assertDirectoryDoesNotExist($workDir);
        $this->assertSame([], $cache->getValue(null));
    }

    public function test_repack_rejects_parent_directory_entry(): void
    {
        $tmp = sys_get_temp_dir() . '/wp-migrator-slip-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        $this->registerFixtureCleanup($tmp);

        $zipPath = $tmp . '/tag.zip';
        $escaped = $tmp . '/work/escaped.php';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('../escaped.php', '<?php echo "nope";');
        $zip->close();

        try {
            (new WordpressMigratorZip())->repackFromTagArchive($zipPath, $tmp . '/work');
            $this->fail('Expected RuntimeException for a parent-directory zip entry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unsafe path', $e->getMessage());
            $this->assertFileDoesNotExist($escaped);
        }
    }

    public function test_repack_fails_when_wordpress_migrator_php_missing(): void
    {
        $fixture = $this->buildTagArchiveFixture([
            'config.php' => "<?php\nreturn [];\n",
            'restore.php' => "<?php\n",
        ]);

        try {
            (new WordpressMigratorZip())->repackFromTagArchive($fixture);
            $this->fail('Expected RuntimeException for missing wordpress-migrator.php');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wordpress-migrator.php', $e->getMessage());
        } finally {
            @unlink($fixture);
        }
    }

    public function test_a_release_before_the_token_was_required_is_still_repacked(): void
    {
        $this->fakeRelease('1.1.4');

        $installZip = $this->download();

        $this->assertFileExists($installZip);
        $this->assertFalse(WordpressMigratorZip::requiresToken($installZip));
    }

    public function test_a_release_from_1_2_0_must_require_the_token(): void
    {
        $this->fakeRelease('1.2.0');

        $this->assertTrue(WordpressMigratorZip::requiresToken($this->download()));
    }

    public function test_a_release_without_a_version_is_not_held_to_the_token(): void
    {
        $this->fakeRelease(null);

        $this->assertFalse(WordpressMigratorZip::requiresToken($this->download()));
    }

    public function test_the_default_release_is_v1_1_4_pinned_by_its_checksum(): void
    {
        $this->assertSame(
            'https://github.com/panelalpha/panelalpha-wordpress-migrator/releases/download/v1.1.4/wordpress-migrator.zip',
            config('services.wordpress_migrator.release_url'),
        );
        $this->assertSame(
            'f636ceb62c5be51525763f4ae71e669ac0c116d7988eb0190c94b8183a8cb13f',
            config('services.wordpress_migrator.sha256'),
        );
    }

    public function test_a_release_that_is_not_the_pinned_one_is_refused_and_leaves_nothing_behind(): void
    {
        $this->fakeRelease('1.1.4');
        config(['services.wordpress_migrator.sha256' => str_repeat('0', 64)]);
        $before = $this->migratorWorkDirs();

        try {
            $this->download();
            $this->fail('A release whose SHA-256 is not the pinned one was repacked');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not the pinned one', $e->getMessage());
            $this->assertStringContainsString('WORDPRESS_MIGRATOR_SHA256', $e->getMessage());
        }

        $this->assertSame($before, $this->migratorWorkDirs());
    }

    public function test_the_pinned_checksum_is_compared_without_regard_to_case(): void
    {
        $this->fakeRelease('1.1.4');
        config(['services.wordpress_migrator.sha256' => strtoupper((string) config('services.wordpress_migrator.sha256'))]);

        $this->assertFileExists($this->download());
    }

    public function test_a_download_inside_a_request_gives_up_sooner_than_one_in_an_import_job(): void
    {
        $seconds = new \ReflectionProperty(WordpressMigratorZip::class, 'downloadSeconds');
        $stepZip = static fn (object $step): WordpressMigratorZip => (new \ReflectionProperty($step, 'zip'))->getValue($step);

        $this->assertSame(WordpressMigratorZip::REQUEST_DOWNLOAD_SECONDS, $seconds->getValue(app(WordpressMigratorZip::class)));
        $this->assertSame(120, WordpressMigratorZip::REQUEST_DOWNLOAD_SECONDS);
        $this->assertSame(WordpressMigratorZip::JOB_DOWNLOAD_SECONDS, $seconds->getValue($stepZip(new InstallDestinationPluginStep())));
        $this->assertSame(WordpressMigratorZip::JOB_DOWNLOAD_SECONDS, $seconds->getValue($stepZip(new InstallSourcePluginStep())));
    }

    public function test_download_failure_raises_clear_error(): void
    {
        try {
            (new WordpressMigratorZip())->downloadAndRepack('http://' . TestNetwork::LOOPBACK . ':1/does-not-exist.zip');
            $this->fail('Expected RuntimeException for failed download');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('download', strtolower($e->getMessage()));
        }
    }

    private function download(): string
    {
        $installZip = (new WordpressMigratorZip())->downloadAndRepack('https://releases.example/wordpress-migrator.zip');
        $this->registerFixtureCleanup(dirname($installZip));

        return $installZip;
    }

    private function fakeRelease(?string $version): void
    {
        $header = $version === null ? '' : " * Version: {$version}\n";
        $archive = $this->buildTagArchiveFixture([
            'wordpress-migrator.php' => "<?php\n/**\n * Plugin Name: WordPress Migrator\n{$header} */\n",
            'config.php' => "<?php\nreturn ['token' => '', 'mode' => 'source'];\n",
        ]);
        $body = (string) file_get_contents($archive);
        config(['services.wordpress_migrator.sha256' => hash('sha256', $body)]);
        Http::fake(['releases.example/*' => Http::response($body)]);
    }

    /**
     * @return list<string>
     */
    private function migratorWorkDirs(): array
    {
        $dirs = glob(sys_get_temp_dir() . '/wp-migrator-*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        return $dirs;
    }

    /**
     * @param array<string, string> $files relative path => contents under the tag top dir
     */
    private function buildTagArchiveFixture(array $files): string
    {
        $tmp = sys_get_temp_dir() . '/wp-migrator-fixture-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $top = $tmp . '/panelalpha-wordpress-migrator-1.1.4';
        mkdir($top, 0700, true);

        foreach ($files as $relative => $contents) {
            $path = $top . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($path, $contents);
        }

        $zipPath = $tmp . '/tag.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($top, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = 'panelalpha-wordpress-migrator-1.1.4/'
                . substr($file->getPathname(), strlen($top) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($relative, '/') . '/');
            } else {
                $zip->addFile($file->getPathname(), $relative);
            }
        }
        $zip->close();

        // Leave extracted tree; only the zip path is returned. Clean later.
        $this->registerFixtureCleanup($tmp);

        return $zipPath;
    }

    /** @var list<string> */
    private array $fixtureDirs = [];

    private function registerFixtureCleanup(string $dir): void
    {
        $this->fixtureDirs[] = $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($dir);
        }
        $this->fixtureDirs = [];
        parent::tearDown();
    }
}
