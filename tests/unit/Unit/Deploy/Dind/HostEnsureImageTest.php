<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * engine#784: a host build runs the base image by its plain name and never
 * pulls it. One of ours is not on docker.io, so a host that pruned it fails
 * with "never published or has been removed". The host must pull it from the
 * cache registry the host pushes to.
 */
class HostEnsureImageTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.3-apache-bookworm-pa20260924';

    public function test_a_public_image_is_left_alone(): void
    {
        // Nothing on docker.io is ours: the host pulls it from its own registry
        // exactly as before, so there is nothing to do here.
        $this->assertSame(['true'], (new DindImageStore())->hostEnsureImageArgv('composer:2'));
        $this->assertSame(['true'], (new DindImageStore())->hostEnsureImageArgv('redis:alpine'));
    }

    public function test_our_image_is_pulled_from_the_cache_registry_when_the_host_lacks_it(): void
    {
        $request = (new DindImageStore())->hostEnsureImageArgv(self::IMAGE);

        $this->assertSame([LocalRoot::CLIENT, '--host', 'host.ensureOwnImage', '--', self::IMAGE], $request);
        $this->assertSame([], LocalRoot::plain($request));
    }

    public function test_our_missing_image_is_rebuilt_under_its_own_lock_and_published_under_the_push_lock(): void
    {
        $script = (new DindImageStore())->hostRebuildCommand(self::IMAGE, "FROM php:8.3-apache-bookworm\n");

        $this->assertStringContainsString('lines.buildFromStdin -- ' . self::IMAGE, $script);
        $this->assertStringContainsString('lines.pushQuietly -- '
            . DindImageStore::HOST_CACHE_REGISTRY . '/' . self::IMAGE, $script);
        // Two deploys cannot build the same image: the build holds a lock of
        // that image's own, exclusively.
        $this->assertStringContainsString(DindImageStore::buildLockPath(self::IMAGE) . ' && flock -x', $script);
        // Only the push takes the lock prewarm's trim holds, and shared, so a
        // long build never holds up another account's image transfer.
        $this->assertStringContainsString(DindImageStore::pushLock() . ' && flock -s', $script);
        $this->assertStringNotContainsString(escapeshellarg(DindImageStore::pushLock()) . ' && flock -x', $script);
    }
}
