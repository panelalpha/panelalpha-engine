<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Pins every refusal of the tag-archive repack and which work directory each one leaves behind.
 */
class WordpressMigratorZipRepackCharacterisationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/wp-migrator-characterise-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_a_missing_archive_is_named(): void
    {
        $this->expectExceptionMessage("Migrator tag archive not found: {$this->tmp}/none.zip");

        (new WordpressMigratorZip)->repackFromTagArchive($this->tmp.'/none.zip');
    }

    public function test_an_archive_that_is_not_a_zip_leaves_a_given_work_dir_in_place(): void
    {
        file_put_contents($this->tmp.'/tag.zip', 'not a zip');

        $this->assertRefusal('Could not open migrator tag archive', $this->tmp.'/tag.zip', $this->tmp.'/work');
        $this->assertDirectoryExists($this->tmp.'/work/extracted');
    }

    public function test_an_archive_that_is_not_a_zip_removes_its_own_work_dir(): void
    {
        file_put_contents($this->tmp.'/tag.zip', 'not a zip');
        $before = $this->ownWorkDirs();

        $this->assertRefusal('Could not open migrator tag archive', $this->tmp.'/tag.zip');
        $this->assertSame($before, $this->ownWorkDirs());
    }

    public function test_an_unsafe_entry_removes_its_own_work_dir(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['../escaped.php' => 'x']);
        $before = $this->ownWorkDirs();

        $this->assertRefusal('Migrator tag archive contains an unsafe path', $this->tmp.'/tag.zip');
        $this->assertSame($before, $this->ownWorkDirs());
    }

    public function test_an_absolute_entry_is_refused(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['/etc/escaped.php' => 'x']);

        $this->assertRefusal('Migrator tag archive contains an unsafe path', $this->tmp.'/tag.zip', $this->tmp.'/work');
    }

    public function test_two_top_level_directories_are_refused(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['a/wordpress-migrator.php' => 'x', 'b/readme.txt' => 'y']);

        $this->assertRefusal(
            'Migrator tag archive must contain a single top-level directory with wordpress-migrator.php',
            $this->tmp.'/tag.zip',
            $this->tmp.'/work',
        );
    }

    public function test_a_missing_main_file_leaves_a_given_work_dir_in_place(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['release-1.0/readme.txt' => 'y']);

        $this->assertRefusal(
            'Migrator tag archive is missing wordpress-migrator.php under the top-level directory',
            $this->tmp.'/tag.zip',
            $this->tmp.'/work',
        );
        $this->assertFileExists($this->tmp.'/work/extracted/release-1.0/readme.txt');
    }

    public function test_a_flat_archive_is_its_own_plugin_root(): void
    {
        $this->zip($this->tmp.'/tag.zip', [
            'wordpress-migrator.php' => '<?php',
            'branding.php' => 'no',
            'inc/' => null,
            'inc/a.php' => 'a',
            'docs/b.txt' => 'b',
        ]);

        $installZip = (new WordpressMigratorZip)->repackFromTagArchive($this->tmp.'/tag.zip', $this->tmp.'/work');

        $this->assertSame($this->tmp.'/work/wordpress-migrator-install.zip', $installZip);
        $this->assertSame([
            'wordpress-migrator/docs/',
            'wordpress-migrator/docs/b.txt',
            'wordpress-migrator/inc/',
            'wordpress-migrator/inc/a.php',
            'wordpress-migrator/wordpress-migrator.php',
        ], $this->entries($installZip));
    }

    public function test_a_flat_archive_with_a_single_directory_is_its_own_plugin_root(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['wordpress-migrator.php' => '<?php', 'src/a.php' => 'a']);

        $installZip = (new WordpressMigratorZip)->repackFromTagArchive($this->tmp.'/tag.zip', $this->tmp.'/work');

        $this->assertSame([
            'wordpress-migrator/src/',
            'wordpress-migrator/src/a.php',
            'wordpress-migrator/wordpress-migrator.php',
        ], $this->entries($installZip));
    }

    public function test_a_branding_file_in_another_case_is_shipped(): void
    {
        $this->zip($this->tmp.'/tag.zip', ['release-1.0/wordpress-migrator.php' => '<?php', 'release-1.0/BRANDING.PHP' => 'x']);

        $installZip = (new WordpressMigratorZip)->repackFromTagArchive($this->tmp.'/tag.zip', $this->tmp.'/work');

        $this->assertContains('wordpress-migrator/BRANDING.PHP', $this->entries($installZip));
    }

    private function assertRefusal(string $message, string $archive, ?string $workDir = null): void
    {
        try {
            (new WordpressMigratorZip)->repackFromTagArchive($archive, $workDir);
            $this->fail('the archive was repacked');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @param array<string, ?string> $files */
    private function zip(string $path, array $files): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $contents === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    /** @return list<string> */
    private function entries(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function ownWorkDirs(): array
    {
        $dirs = glob(sys_get_temp_dir().'/wp-migrator-repack-*') ?: [];
        sort($dirs);

        return $dirs;
    }
}
