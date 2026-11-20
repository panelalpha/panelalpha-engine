<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Php;

use PHPUnit\Framework\TestCase;
use Tests\Support\AccountUserFilesFixtures;
use Tests\Support\LocalRoot;

/**
 * The PHP container runs as the bare account uid, which the base image does
 * not know: posix_getpwuid() returned false and Passbolt died on boot.
 */
class AccountUserFilesTest extends TestCase
{
    use AccountUserFilesFixtures;

    public function test_the_files_are_read_from_the_host_image_without_pulling(): void
    {
        $this->files()->for(self::IMAGE, 1246, 1246);

        $this->assertSame('docker.readFileFromImage', LocalRoot::action($this->calls[0]));
        $this->assertSame(['0', self::IMAGE, '/etc/passwd'], LocalRoot::values($this->calls[0]));
        $this->assertSame([], LocalRoot::plain($this->calls[0]));
    }
}
