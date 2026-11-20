<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The install zip around the edges: a cached copy reused, a temp path it does not own left alone, a refused
 * download, and a zip with nowhere to put the token.
 */
class WordpressMigratorZipEdgesTest extends TestCase
{
    private const RELEASE = 'https://releases.example/wordpress-migrator.zip';

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCache();
    }

    protected function tearDown(): void
    {
        $this->forgetCache();
        foreach (array_reverse($this->paths) as $path) {
            $this->remove($path);
        }
        parent::tearDown();
    }

    public function test_a_run_reuses_the_zip_it_already_repacked(): void
    {
        $this->fakeRelease();
        $zips = new WordpressMigratorZip;

        $first = $zips->installZipFor(7, self::RELEASE);
        $this->paths[] = dirname($first);
        $second = $zips->installZipFor(7, self::RELEASE);

        $this->assertSame($first, $second);
        $this->assertFileExists($second);
        Http::assertSentCount(1);
    }

    public function test_a_cached_path_outside_the_migrator_temp_prefix_is_not_removed(): void
    {
        $dir = $this->tempDir('pa-not-a-migrator-');
        file_put_contents($dir.'/install.zip', 'zip');
        (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->setValue(null, [9 => $dir.'/install.zip']);

        WordpressMigratorZip::discard(9);

        $this->assertFileExists($dir.'/install.zip');
        $this->assertSame([], (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->getValue());
    }

    public function test_a_cached_work_dir_that_is_already_gone_is_forgotten(): void
    {
        $gone = sys_get_temp_dir().'/wp-migrator-'.bin2hex(random_bytes(6));
        (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->setValue(null, [11 => $gone.'/install.zip']);

        WordpressMigratorZip::discard(11);

        $this->assertDirectoryDoesNotExist($gone);
        $this->assertSame([], (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->getValue());
    }

    public function test_a_release_the_server_refuses_fails_with_its_status(): void
    {
        Http::fake(['releases.example/*' => Http::response('gone', 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to download WordPress migrator release (HTTP 404): '.self::RELEASE);

        (new WordpressMigratorZip)->downloadAndRepack(self::RELEASE);
    }

    public function test_a_file_that_is_not_a_zip_takes_no_token_and_has_no_version(): void
    {
        $file = $this->tempDir('pa-not-a-zip-').'/install.zip';
        file_put_contents($file, 'not a zip');

        $this->assertNull(WordpressMigratorZip::versionOf($file));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open migrator install zip');

        WordpressMigratorZip::writeToken($file, 't0ken');
    }

    public function test_a_zip_without_a_config_has_no_token_to_write(): void
    {
        $zip = $this->installZip(['wordpress-migrator/wordpress-migrator.php' => "<?php\n"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migrator install zip has no token in wordpress-migrator/config.php');

        WordpressMigratorZip::writeToken($zip, 't0ken');
    }

    public function test_a_config_without_a_token_entry_is_refused(): void
    {
        $zip = $this->installZip(['wordpress-migrator/config.php' => "<?php\nreturn ['mode' => 'source'];\n"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migrator install zip has no token in wordpress-migrator/config.php');

        WordpressMigratorZip::writeToken($zip, 't0ken');
    }

    private function fakeRelease(): void
    {
        $archive = $this->installZip([
            'wordpress-migrator.php' => "<?php\n/**\n * Plugin Name: WordPress Migrator\n * Version: 1.1.4\n */\n",
            'config.php' => "<?php\nreturn ['token' => '', 'mode' => 'source'];\n",
        ]);
        $body = (string) file_get_contents($archive);
        config(['services.wordpress_migrator.sha256' => hash('sha256', $body)]);
        Http::fake(['releases.example/*' => Http::response($body)]);
    }

    /** @param  array<string, string>  $entries */
    private function installZip(array $entries): string
    {
        $path = $this->tempDir('pa-migrator-zip-').'/archive.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->paths[] = $dir;

        return $dir;
    }

    private function forgetCache(): void
    {
        (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->setValue(null, []);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path.'/'.$entry);
        }
        rmdir($path);
    }
}
