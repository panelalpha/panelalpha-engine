<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use RuntimeException;
use ZipArchive;

/**
 * Tag-archive fixture → install zip with wordpress-migrator/ root, no network.
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

    public function test_download_failure_raises_clear_error(): void
    {
        try {
            (new WordpressMigratorZip())->downloadAndRepack('http://127.0.0.1:1/does-not-exist.zip');
            $this->fail('Expected RuntimeException for failed download');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('download', strtolower($e->getMessage()));
        }
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
