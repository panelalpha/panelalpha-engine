<?php

namespace Tests\Integration\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use PHPUnit\Framework\TestCase;

/**
 * A recipe directory's files, nested ones by their relative path, and a link to nothing left out.
 */
class LocalAppConfigSourceListingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-local-source-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/files/etc', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_link_to_nothing_is_not_a_file_of_the_directory(): void
    {
        file_put_contents($this->dir.'/panelalpha.yaml', "env:\n  A: b\n");
        file_put_contents($this->dir.'/files/etc/app.conf', 'x');
        symlink($this->dir.'/missing', $this->dir.'/dangling');

        $this->assertSame(['files/etc/app.conf', 'panelalpha.yaml'], (new LocalAppConfigSource)->listFiles($this->dir));
        $this->assertSame([], (new LocalAppConfigSource)->listFiles($this->dir.'/missing'));
    }
}
