<?php

namespace Tests\Unit\Deploy\Dind;

use PHPUnit\Framework\TestCase;
use Tests\Support\HostCompileRustLibrariesFixtures;

/**
 * After a Rust host compile, the binary is checked in the image it will run
 * in. Only a gap costs the build-image copy and the second check; a
 * binary slim can run gets one container and nothing else.
 */
class HostCompileRustLibrariesTest extends TestCase
{
    use HostCompileRustLibrariesFixtures;

    /** A manifest that pinned one image compiles and runs in it: nothing to compare. */
    public function test_one_image_for_both_is_not_checked(): void
    {
        $this->assertSame([], $this->bundle('ghcr.io/acme/rust:1', 'ghcr.io/acme/rust:1', true));
        $this->assertSame([], $this->bundle('rust:1-bookworm', '', true));
    }
}
