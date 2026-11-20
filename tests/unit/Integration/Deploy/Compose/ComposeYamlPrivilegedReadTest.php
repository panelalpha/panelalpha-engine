<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Compose\ComposeYaml;
use PHPUnit\Framework\TestCase;

/** A file in an account home that the plain read refuses goes to the privileged reader, which reads it as the account. */
class ComposeYamlPrivilegedReadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-privileged-compose-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/acme/project', 0777, true);
        mkdir($this->dir . '/other', 0777, true);
        AccountHomeRead::useHomesDir($this->dir);
    }

    protected function tearDown(): void
    {
        ComposeYaml::readPrivilegedWith(null);
        AccountHomeRead::useHomesDir(null);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_link_into_another_home_is_not_read_as_the_core_but_handed_to_the_privileged_reader(): void
    {
        file_put_contents($this->dir . '/other/compose.yml', "services:\n  app:\n    image: other/secret\n");
        $path = $this->dir . '/acme/project/docker-compose.yml';
        symlink($this->dir . '/other/compose.yml', $path);
        $asked = [];
        ComposeYaml::readPrivilegedWith(static function (string $p) use (&$asked): ?string {
            $asked[] = $p;

            return null;
        });

        $this->assertNull(ComposeYaml::read($path));
        $this->assertNull(ComposeYaml::parseFile($path));
        $this->assertSame([$path, $path], $asked);
    }
}
