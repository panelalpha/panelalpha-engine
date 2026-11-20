<?php

namespace Tests\Integration\Deploy\Dind;

use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompileOwnImageFixtures;

/**
 * A host build runs its image by name on the host's docker, so the host gets
 * one of ours first; when it cannot, the deploy fails naming that base.
 */
class HostCompileOwnImageTest extends TestCase
{
    use HostCompileOwnImageFixtures;

    /** Ours is never pulled from docker.io: nothing publishes it there. */
    public function test_one_of_ours_is_put_on_the_host_before_the_build_runs_it(): void
    {
        $run = $this->build([self::OURS], true);

        $this->assertSame([self::OURS], $this->ensured);
        $this->assertSame([self::OURS], $run['containers']);
        $this->assertSame([], $run['pulls']);
    }

    public function test_it_is_provided_once_per_deploy(): void
    {
        $run = $this->build([self::OURS, self::OURS], true);

        $this->assertSame([self::OURS], $this->ensured);
        $this->assertSame([self::OURS, self::OURS], $run['containers']);
    }

    public function test_a_public_image_is_refreshed_and_never_asked_of_the_cache_registry(): void
    {
        $run = $this->build(['node:22-bookworm-slim'], true);

        $this->assertSame([], $this->ensured);
        $this->assertSame(['node:22-bookworm-slim'], $run['containers']);
        $this->assertSame(['node:22-bookworm-slim'], $run['pulls']);
    }
}
