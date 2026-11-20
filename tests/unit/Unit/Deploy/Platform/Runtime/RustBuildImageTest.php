<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use PHPUnit\Framework\TestCase;
use Tests\Support\RustBuildImageFixtures;

/**
 * A Rust host compile does not run in the image the app runs in.
 *
 * `rust:1-slim-bookworm` ships the toolchain and nothing a build script can
 * link against:
 *
 *     rust:1-bookworm       g++ present   pkg-config present
 *                           /usr/include/openssl/ssl.h present   libssl.so present
 *     rust:1-slim-bookworm  g++ MISSING   pkg-config MISSING
 *                           no openssl headers                  no libssl.so
 *
 * A host compile runs as the account, so `RustRuntime::systemPackages()` --
 * which wants to apt-get `build-essential pkg-config libssl-dev` -- cannot
 * install any of it. Every crate with a build script that probes for a
 * compiler, a header or a library therefore fails, and says so about the
 * crate: `rust-librocksdb-sys` for want of libclang, `openssl-sys` for want of
 * the headers, `glib-sys`, `libsodium-sys`, `libduckdb-sys` the same way.
 *
 * The catalogue can point the runtime somewhere else, and when an operator has
 * done that the compile has to follow -- guessing the other way would compile
 * in an image the app does not run in, which is the bug this exists to avoid.
 */
class RustBuildImageTest extends TestCase
{
    use RustBuildImageFixtures;

    /**
     * The whole point: the compile image is not the runtime image.
     *
     * If these two ever collapse back into one, the slim tag is what the
     * compile gets again and the crates above fail again.
     */
    public function test_the_compile_image_is_not_the_slim_runtime_image(): void
    {
        RuntimeImageCatalog::useConfig(null);

        $this->assertSame('rust:1-slim-bookworm', RustRuntime::imageTag(RustRuntime::VERSION));
        $this->assertSame('rust:1-bookworm', RustRuntime::buildImageTag(RustRuntime::VERSION));
        $this->assertNotSame(
            RustRuntime::imageTag(RustRuntime::VERSION),
            RustRuntime::buildImageTag(RustRuntime::VERSION),
            'a Rust host compile must not run in the slim runtime image'
        );
    }

    /**
     * The host compile is where the build image has to arrive. It resolved
     * `buildImageTag()` nowhere, so cargo ran in slim and died on the first
     * crate wanting g++, make, or pkg-config and OpenSSL.
     */
    public function test_the_host_compile_runs_cargo_in_the_build_image(): void
    {
        RuntimeImageCatalog::useConfig(null);

        $compile = new HostCompile($this->createStub(Dind::class));
        $image = (new \ReflectionMethod($compile, 'commandRuntimeImage'))->invoke(
            $compile,
            ['strategy' => 'rust', 'image' => 'rust:1-slim-bookworm'],
            '/nonexistent'
        );

        $this->assertSame('rust:1-bookworm', $image);
    }

    public function test_only_the_runtime_tag_is_swapped_for_the_build_tag(): void
    {
        RuntimeImageCatalog::useConfig(null);

        $this->assertSame('rust:1-bookworm', RustRuntime::compileImage('rust:1-slim-bookworm'));
        $this->assertSame('rust:1-bookworm', RustRuntime::compileImage(''));
        // A manifest that pinned its own image chose what to compile in.
        $this->assertSame('ghcr.io/acme/rust-musl:1', RustRuntime::compileImage('ghcr.io/acme/rust-musl:1'));
    }
}
