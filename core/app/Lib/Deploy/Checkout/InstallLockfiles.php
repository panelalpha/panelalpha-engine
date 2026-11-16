<?php

namespace App\Lib\Deploy\Checkout;

/**
 * The lockfiles an install writes into a checkout that ships none, by the manifest beside it: the engine's own output,
 * since only a repository that tracks one owns it (ADR-0001).
 */
final class InstallLockfiles
{
    /**
     * Every manifest a build installs from, with the lockfile its package manager writes when it finds none.
     *
     * @var array<string, list<string>>
     */
    private const array BESIDE = [
        'package.json' => ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'],
        'composer.json' => ['composer.lock'],
        'Cargo.toml' => ['Cargo.lock'],
        'pyproject.toml' => ['poetry.lock'],
        'Pipfile' => ['Pipfile.lock'],
    ];

    /**
     * The folders a package manager installs the project's dependencies into: other packages' files, never the
     * project's own, so an install writes no lockfile beside their manifests.
     *
     * @var list<string>
     */
    private const array DEPENDENCIES = ['node_modules', 'vendor', 'bower_components'];

    /**
     * Checkout-relative paths the install may write: the lockfiles of every manifest at the top of the checkout or in
     * one of its folders, where an application's `app_root` and a workspace sit.
     *
     * @return list<string>
     */
    public static function candidates(string $checkout): array
    {
        $root = rtrim($checkout, '/');

        $paths = [];
        foreach (self::folders($root) as $folder) {
            foreach (self::BESIDE as $manifest => $lockfiles) {
                if (! is_file($root.'/'.$folder.$manifest)) {
                    continue;
                }
                foreach ($lockfiles as $lockfile) {
                    $paths[] = $folder.$lockfile;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Checkout-relative folders an install may fill with the dependencies: at the top of the checkout, and beside
     * every manifest in one of its folders, where the lockfiles are looked for too.
     *
     * @return list<string>
     */
    public static function dependencyFolders(string $checkout): array
    {
        $root = rtrim($checkout, '/');

        $paths = [];
        foreach (self::folders($root) as $folder) {
            if ($folder !== '' && ! self::holdsManifest($root.'/'.$folder)) {
                continue;
            }
            foreach (self::DEPENDENCIES as $dependency) {
                $paths[] = $folder.$dependency;
            }
        }

        return $paths;
    }

    private static function holdsManifest(string $folder): bool
    {
        foreach (array_keys(self::BESIDE) as $manifest) {
            if (is_file($folder.$manifest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * '' for the checkout itself, then every folder a manifest of the project may sit in.
     *
     * @return list<string>
     */
    private static function folders(string $root): array
    {
        $folders = [''];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $entry = basename($path);
            if (! in_array($entry, self::DEPENDENCIES, true)) {
                $folders[] = $entry.'/';
            }
        }

        return $folders;
    }
}
