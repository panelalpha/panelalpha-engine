<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostEnsureImageFixtures;
use Tests\Support\LocalRoot;

/**
 * engine#784: a host build runs the base image by its plain name and never
 * pulls it. One of ours is not on docker.io, so a host that pruned it fails
 * with "never published or has been removed". The host must pull it from the
 * cache registry the host pushes to.
 */
class HostEnsureImageTest extends TestCase
{
    use HostEnsureImageFixtures;

    public function test_our_image_is_pulled_from_the_cache_registry_when_the_host_lacks_it(): void
    {
        $request = (new DindImageStore())->hostEnsureImageArgv(self::IMAGE);

        $this->assertSame([LocalRoot::CLIENT, '--host', 'host.ensureOwnImage', '--', self::IMAGE], $request);
        $this->assertSame([], LocalRoot::plain($request));
    }
}
