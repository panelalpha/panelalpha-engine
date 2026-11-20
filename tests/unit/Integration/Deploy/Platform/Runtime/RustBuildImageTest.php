<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
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

    private function config(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'runtime-images-') . '.yaml';
        file_put_contents($path, $yaml);
        $this->written[] = $path;
        RuntimeImageCatalog::useConfig($path);
    }

    /**
     * A host that points the runtime at an image of its own gets that image
     * for the compile too, unless it names a separate one.
     */
    public function test_the_operators_image_is_what_the_compile_uses(): void
    {
        $this->config(<<<'YAML'
        runtimes:
          rust:
            default: "1"
            image:
              from: "registry.internal/rust:1-debian"
              build_from: "registry.internal/rust:1-debian-toolchain"
        YAML);

        $this->assertSame('registry.internal/rust:1-debian', RustRuntime::imageTag('1'));
        $this->assertSame('registry.internal/rust:1-debian-toolchain', RustRuntime::buildImageTag('1'));

        // ...and without a `build_from`, the runtime image is the compile
        // image -- never a Docker Hub tag the operator replaced.
        $this->config(<<<'YAML'
        runtimes:
          rust:
            default: "1"
            image:
              from: "registry.internal/rust:1-debian"
        YAML);

        $this->assertSame('registry.internal/rust:1-debian', RustRuntime::buildImageTag('1'));
    }

    /**
     * A config that names no Rust at all -- the common case, and the one a
     * half-edited file leaves behind -- still gets a compile-capable image.
     */
    public function test_a_config_silent_about_rust_still_gets_a_compile_image(): void
    {
        $this->config(<<<'YAML'
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
        YAML);

        $this->assertSame('rust:1-bookworm', RustRuntime::buildImageTag('1'));
    }

    /**
     * The apt best effort runs as the account and can only fail; in an image
     * that already has the tools it must not run, or its permission error is
     * the first line of every Rust failure report.
     */
    public function test_system_packages_do_not_run_apt_when_the_tools_are_there(): void
    {
        $this->assertSame(['apt-get'], $this->aptCallsWith([]));
        $this->assertSame([], $this->aptCallsWith(['c++', 'make', 'pkg-config']));
        $this->assertSame(['apt-get'], $this->aptCallsWith(['c++', 'make']));
    }

    /**
     * Run systemPackages() with only the named tools on PATH, plus an apt-get
     * that records being called and fails the way it does for the account.
     *
     * @param list<string> $tools
     * @return list<string>
     */
    private function aptCallsWith(array $tools): array
    {
        $bin = sys_get_temp_dir() . '/rust-tools-' . bin2hex(random_bytes(4));
        mkdir($bin);
        $log = $bin . '/calls';
        foreach ([...$tools, 'apt-get'] as $tool) {
            $body = $tool === 'apt-get' ? "echo apt-get >> {$log}; exit 100" : 'exit 0';
            file_put_contents("{$bin}/{$tool}", "#!/bin/sh\n{$body}\n");
            chmod("{$bin}/{$tool}", 0755);
        }
        $sh = trim((string) shell_exec('command -v sh'));

        exec('env -i PATH=' . escapeshellarg($bin) . ' ' . escapeshellarg($sh) . ' -c '
            . escapeshellarg(RustRuntime::systemPackages()), $out, $code);
        $calls = is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
        array_map('unlink', glob($bin . '/*') ?: []);
        rmdir($bin);

        $this->assertSame(0, $code, 'the best effort must never fail the build chain');

        return array_values(array_unique($calls));
    }
}
