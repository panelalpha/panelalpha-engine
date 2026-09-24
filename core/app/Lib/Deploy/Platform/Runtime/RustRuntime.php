<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Rust.
 *
 * Versioned by Cargo.toml's `rust-version`, which is a minimum supported
 * version, not a request for a toolchain: the deploy stays on the rolling
 * `rust:1` image and records the declared minimum in the deploy log.
 */
final class RustRuntime implements Runtime
{
    /**
     * The rolling 1.x image, and the compiled-in fallback for the catalogue's
     * `image.from`. A const cannot read the catalogue; `image()` and
     * `defaultImage()` honour a configured one.
     */
    public const IMAGE = 'rust:1-slim-bookworm';

    /** The only version there is; see the class note. */
    public const VERSION = '1';

    public static function defaultImage(): string
    {
        return self::imageTag(self::VERSION);
    }

    public static function imageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('rust', $version);

        return $spec?->from ?? self::IMAGE;
    }

    public function id(): string
    {
        return 'rust';
    }

    public function resolve(ProjectContext $context): ?Requirement
    {
        $cargo = $context->contents('Cargo.toml');
        if ($cargo === null) {
            return null;
        }

        if (preg_match('/rust-version\s*=\s*[\'"]([^\'"]+)[\'"]/', $cargo, $matches) === 1) {
            return new Requirement('rust', self::VERSION, $matches[1], 'Cargo.toml rust-version');
        }

        return new Requirement('rust', self::VERSION, '', 'Cargo.toml');
    }

    /**
     * Always the rolling image, whatever the requirement's constraint was: the
     * declared minimum is carried for the deploy log, not acted on.
     */
    public function image(Requirement $requirement): string
    {
        return self::imageTag(self::VERSION);
    }

    /**
     * The full tag, not *slim*, which ships the toolchain but no `g++`, no
     * `pkg-config` and no OpenSSL headers, so `openssl-sys` and the `-sys`
     * crates fail. `systemPackages()` cannot recover that: a host compile runs
     * as the account and has no root.
     *
     * 2.2GB against 1.23GB; the image is loaded on the host, so every account
     * shares one transfer. `HostCompile::commandRuntimeImage()` makes the same
     * trade for Ruby and Python.
     */
    public const BUILD_IMAGE = 'rust:1-bookworm';

    /**
     * The image a host compile should run in: cargo needs a toolchain the
     * runtime image does not carry.
     *
     * `build_from` in `config/core/images.yaml` wins, falling back to that
     * image; only with no config does `BUILD_IMAGE` apply. Compiling in an
     * image the app does not run in is the failure this avoids.
     */
    public static function buildImageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('rust', $version);

        return $spec?->buildFrom ?? $spec?->from ?? self::BUILD_IMAGE;
    }

    /**
     * What a host compile runs in, given the image the recipe resolved: the
     * runtime tag swaps to the build tag, an image pinned elsewhere is kept.
     */
    public static function compileImage(string $declared): string
    {
        $declared = trim($declared);
        if ($declared === '' || $declared === self::imageTag(self::VERSION)) {
            return self::buildImageTag(self::VERSION);
        }

        return $declared;
    }

    /**
     * `build-essential pkg-config libssl-dev` — what the common crates link
     * against. Best effort, because a host compile runs as the account and
     * cannot install anything: failing later in cargo, at the crate that wanted
     * the header, beats failing first at all of them. Skipped when the image
     * already has the tools, so apt's permission error does not reach the log.
     */
    public static function systemPackages(): string
    {
        return '{ command -v c++ && command -v make && command -v pkg-config; } >/dev/null 2>&1'
            . ' || { apt-get update'
            . ' && apt-get install -y --no-install-recommends build-essential pkg-config libssl-dev ca-certificates'
            . ' && rm -rf /var/lib/apt/lists/*; } || true';
    }

    /**
     * `--locked` when the repo ships a lockfile, so the committed dependency
     * versions are built. With a fallback: a committed lock is routinely a
     * little stale and `--locked` makes that fatal on
     * `error: cannot update the lock file … because --locked was passed` before
     * a single crate compiles. Same bargain as `composer install`.
     */
    public static function buildCommand(string $projectDir): string
    {
        if (!is_file(rtrim($projectDir, '/') . '/Cargo.lock')) {
            return 'cargo build --release';
        }

        return 'cargo build --release --locked || cargo build --release';
    }

    /**
     * A missing or unexecutable binary restart-loops behind a 502 with nothing
     * in the deploy log to explain it.
     */
    public static function startCommand(string $projectDir): string
    {
        $path = './' . self::binaryPath($projectDir);

        // Cargo's name first; failing that, the only executable in
        // target/release runs instead.
        return 'b=' . $path . ';'
            . ' [ -x "$b" ] || { set -- $(find ./target/release -maxdepth 1 -type f -perm -u+x'
            . ' ! -name \'*.d\' ! -name \'*.so\' -printf \'%f \' 2>/dev/null);'
            . ' if [ $# -eq 1 ]; then b=./target/release/$1;'
            . ' else echo "PANELALPHA: no runnable Rust binary at ' . $path . '";'
            // Name the built binaries so the user can pick one as the start command.
            . ' echo "PANELALPHA: built binaries: $*";'
            . ' echo "PANELALPHA: set a start command naming the one to serve";'
            . ' exit 1; fi; };'
            . ' exec "$b"';
    }

    /**
     * Path of the binary `cargo build --release` produces, relative to the
     * project root. `binaryName()` reads `[[bin]] name`, then `[package] name`,
     * keeping dashes; a workspace root has neither.
     */
    public static function binaryPath(string $projectDir): string
    {
        return 'target/release/' . (self::binaryName($projectDir) ?? 'app');
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('rust', self::VERSION, '', 'engine default');
    }


    public static function binaryName(string $projectDir): ?string
    {
        $manifest = @file_get_contents(rtrim($projectDir, '/') . '/Cargo.toml');
        if (!is_string($manifest) || $manifest === '') {
            return null;
        }

        // An explicit [[bin]] name wins: a crate can ship a binary with a
        // different name from the package.
        if (preg_match('/\[\[bin\]\][^\[]*?\bname\s*=\s*["\']([^"\']+)["\']/s', $manifest, $m) === 1) {
            return self::sanitizeCrateName($m[1]);
        }
        if (preg_match('/\[package\][^\[]*?\bname\s*=\s*["\']([^"\']+)["\']/s', $manifest, $m) === 1) {
            return self::sanitizeCrateName($m[1]);
        }

        return null;
    }



    private static function sanitizeCrateName(string $name): ?string
    {
        $name = trim($name);

        return preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1 ? $name : null;
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        // One image, unpinned: `rust:1-slim-bookworm` tracks the 1.x line.
        return [self::VERSION];
    }
}
