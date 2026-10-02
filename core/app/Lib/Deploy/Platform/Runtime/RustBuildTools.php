<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Build tools a Rust project's build scripts call that `rust:1-bookworm` lacks.
 *
 * A host compile runs as the account and cannot apt-get, so these have to be
 * in the image before cargo starts. Read from what the project commits:
 *
 *   Cargo.lock  prost-build / tonic-build  -> protoc (+ the well-known .proto files)
 *               cmake (aws-lc-sys, ...)    -> cmake
 *               bindgen / clang-sys        -> libclang
 *   .cargo/config[.toml]  -fuse-ld=mold|lld, linker = "clang" -> that linker
 *
 * A lockfile names every platform's dependencies, so this can over-install;
 * a package too many costs image size once per host, one too few fails the build.
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

        $lock = @file_get_contents($dir . '/Cargo.lock');
        if (is_string($lock) && preg_match_all('/^name\s*=\s*"([A-Za-z0-9_-]+)"/m', $lock, $m) > 0) {
            foreach (array_unique($m[1]) as $crate) {
                foreach (self::CRATES[$crate] ?? [] as $package) {
                    $packages[$package] = true;
                }
            }
        }

        foreach (['/.cargo/config.toml', '/.cargo/config'] as $file) {
            $config = @file_get_contents($dir . $file);
            if (!is_string($config)) {
                continue;
            }
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
        }

        $packages = array_keys($packages);
        sort($packages);

        return $packages;
    }
}
