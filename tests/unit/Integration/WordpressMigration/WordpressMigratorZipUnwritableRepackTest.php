<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** A tag archive with a nameless entry is refused, and so is a work directory the install zip cannot be written into. */
class WordpressMigratorZipUnwritableRepackTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/wp-migrator-unwritable-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_an_entry_without_a_name_is_refused(): void
    {
        file_put_contents($this->tmp.'/tag.zip', self::storedZip('', 'x'));

        $this->assertRefusal('Migrator tag archive has an unreadable entry', $this->tmp.'/tag.zip', $this->tmp.'/work');
    }

    public function test_an_install_zip_path_taken_by_a_directory_is_refused_and_the_given_work_dir_kept(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->tmp.'/tag.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('migrator-1.1.4/wordpress-migrator.php', "<?php\n/* Version: 1.1.4 */\n");
        $zip->close();
        mkdir($this->tmp.'/work/wordpress-migrator-install.zip', 0700, true);

        $this->assertRefusal('Could not create migrator install zip', $this->tmp.'/tag.zip', $this->tmp.'/work');
        $this->assertFileExists($this->tmp.'/work/extracted/migrator-1.1.4/wordpress-migrator.php');
    }

    private function assertRefusal(string $message, string $archive, string $workDir): void
    {
        try {
            (new WordpressMigratorZip)->repackFromTagArchive($archive, $workDir);
            $this->fail('the archive was repacked');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** One stored (uncompressed) entry, written by hand so its name can be empty. */
    private static function storedZip(string $name, string $data): string
    {
        $crc = crc32($data);
        $size = strlen($data);
        $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0).$name.$data;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, 0).$name;

        return $local.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    }
}
