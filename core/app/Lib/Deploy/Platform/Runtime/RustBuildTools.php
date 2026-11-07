<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;

/**
 * Build tools a Rust project's build scripts call that `rust:1-bookworm` lacks, read from Cargo.lock
 * and .cargo/config; a host compile cannot apt-get, and a package too many beats a failed build.
 */
final class RustBuildTools
{
    /** Cargo.lock package name => apt packages its build script needs. */
    private const CRATES = [
        'prost-build' => ['protobuf-compiler', 'libprotobuf-dev'],
        'tonic-build' => ['protobuf-compiler', 'libprotobuf-dev'],
        'tonic-prost-build' => ['protobuf-compiler', 'libprotobuf-dev'],
        'cmake' => ['cmake'],
        'bindgen' => ['clang', 'libclang-dev'],
        'clang-sys' => ['clang', 'libclang-dev'],
    ];

    /** `-fuse-ld=` value => apt package. */
    private const LINKERS = [
        'mold' => 'mold',
        'lld' => 'lld',
    ];

    /**
     * @return list<string> apt packages, sorted, empty when the stock image will do
     */
    public static function packages(string $projectDir): array
    {
        $dir = rtrim($projectDir, '/');
        $packages = [];

        $lock = AccountHomeRead::contents($dir.'/Cargo.lock');
        if (is_string($lock)) {
            $packages = self::withCratePackages($packages, $lock);
        }

        foreach (['/.cargo/config.toml', '/.cargo/config'] as $file) {
            $config = AccountHomeRead::contents($dir.$file);
            if (! is_string($config)) {
                continue;
            }
            $packages = self::withLinkerPackages($packages, $config);
        }

        $packages = array_keys($packages);
        sort($packages);

        return $packages;
    }

    /**
     * @param  array<string, true>  $packages
     * @return array<string, true>
     */
    private static function withCratePackages(array $packages, string $lock): array
    {
        if (preg_match_all('/^name\s*=\s*"([A-Za-z0-9_-]+)"/m', $lock, $m) > 0) {
            foreach (array_unique($m[1]) as $crate) {
                foreach (self::CRATES[$crate] ?? [] as $package) {
                    $packages[$package] = true;
                }
            }
        }

        return $packages;
    }

    /**
     * @param  array<string, true>  $packages
     * @return array<string, true>
     */
    private static function withLinkerPackages(array $packages, string $config): array
    {
        if (preg_match_all('/-fuse-ld=([a-z]+)/', $config, $m) > 0) {
            foreach ($m[1] as $linker) {
                if (isset(self::LINKERS[$linker])) {
                    $packages[self::LINKERS[$linker]] = true;
                }
            }
        }
        if (preg_match('/^\s*linker\s*=\s*"(?:[^"]*\/)?clang(?:\+\+)?"/m', $config) === 1) {
            $packages['clang'] = true;
        }

        return $packages;
    }
}
