<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Rust, whose Cargo.toml `rust-version` is a minimum, not a toolchain request: the deploy stays on the rolling
 * `rust:1` image and records the declared minimum in the deploy log.
 */
final class RustRuntime implements Runtime
{
    /**
     * The rolling 1.x image, fallback for the catalogue's `image.from`, which `image()` and `defaultImage()`
     * honour.
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
     * The full tag: slim lacks the `g++`, `pkg-config` and OpenSSL headers a rootless host compile cannot install.
     * 2.2GB against 1.23GB, but loaded once on the host.
     */
    public const BUILD_IMAGE = 'rust:1-bookworm';

    /**
     * The image a host compile runs in, carrying cargo's toolchain: the catalogue's `build_from`, then its
     * `from`, and `BUILD_IMAGE` only with no config.
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
     * What common crates link against, best effort since a host compile cannot install anything, and skipped
     * when the image has the tools, so apt's permission error stays out of the log.
     */
    public static function systemPackages(): string
    {
        return '{ command -v c++ && command -v make && command -v pkg-config; } >/dev/null 2>&1'
            .' || { apt-get update'
            .' && apt-get install -y --no-install-recommends build-essential pkg-config libssl-dev ca-certificates'
            .' && rm -rf /var/lib/apt/lists/*; } || true';
    }

    /**
     * `--locked` with a lockfile, so the committed versions are built, falling back unlocked since a slightly
     * stale lock is fatal under `--locked`.
     */
    public static function buildCommand(string $projectDir): string
    {
        if (! is_file(rtrim($projectDir, '/').'/Cargo.lock')) {
            return 'cargo build --release';
        }

        return 'cargo build --release --locked || cargo build --release';
    }

    /**
     * Cargo's binary, else the only executable in target/release, else the built ones named so the user can
     * pick: a missing binary would restart-loop silently. {@see RustRuntimeLibraries}
     */
    public static function startCommand(string $projectDir): string
    {
        $path = './'.self::binaryPath($projectDir);

        return RustRuntimeLibraries::startPrefix()
            .' b='.$path.';'
            .' [ -x "$b" ] || { set -- $(find ./target/release -maxdepth 1 -type f -perm -u+x'
            .' ! -name \'*.d\' ! -name \'*.so\' -printf \'%f \' 2>/dev/null);'
            .' if [ $# -eq 1 ]; then b=./target/release/$1;'
            .' else echo "PANELALPHA: no runnable Rust binary at '.$path.'";'
            .' echo "PANELALPHA: built binaries: $*";'
            .' echo "PANELALPHA: set a start command naming the one to serve";'
            .' exit 1; fi; };'
            .' exec "$b"';
    }

    /**
     * The binary `cargo build --release` produces, relative to the project root; `app` when `binaryName()`
     * finds no name, as for a workspace root.
     */
    public static function binaryPath(string $projectDir): string
    {
        return 'target/release/'.(self::binaryName($projectDir) ?? 'app');
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('rust', self::VERSION, '', 'engine default');
    }

    /** An explicit `[[bin]] name` wins over `[package] name`: a crate's binary may be named differently. */
    public static function binaryName(string $projectDir): ?string
    {
        $manifest = AccountHomeRead::contents(rtrim($projectDir, '/').'/Cargo.toml');
        if (! is_string($manifest) || $manifest === '') {
            return null;
        }

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
     * One image, unpinned: `rust:1-slim-bookworm` tracks the 1.x line.
     *
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return [self::VERSION];
    }
}
