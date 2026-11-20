<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
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

    public function test_a_binary_the_runtime_image_can_run_costs_one_check_and_nothing_else(): void
    {
        $this->assertSame(
            [['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::checkScript()]],
            $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', false)
        );
    }

    public function test_a_gap_is_filled_from_the_build_image_and_checked_again_in_the_runtime(): void
    {
        $this->assertSame(
            [
                ['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::checkScript()],
                ['image' => 'rust:1-bookworm', 'script' => RustRuntimeLibraries::bundleScript()],
                ['image' => 'rust:1-slim-bookworm', 'script' => RustRuntimeLibraries::verifyScript()],
            ],
            $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', true)
        );
    }

    /** A runtime image the check cannot run in keeps the behaviour it had. */
    public function test_a_check_that_cannot_run_does_not_fail_the_deploy(): void
    {
        $containers = $this->bundle('rust:1-bookworm', 'gcr.io/distroless/cc-debian12:latest', true, [125]);

        $this->assertCount(1, $containers);
    }

    public function test_a_library_still_missing_after_bundling_fails_the_deploy(): void
    {
        $this->expectException(\Exception::class);

        $this->bundle('rust:1-bookworm', 'rust:1-slim-bookworm', true, [0, 0, 1]);
    }
}
