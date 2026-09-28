<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * composer.json and composer.lock, decoded once.
 *
 * The lockfile is what makes an extension list complete: a package the app
 * only depends on transitively still needs its `ext-*` compiled in, and only
 * the lock knows about it.
 */
final class ComposerManifest
{
    /** @var array<string, mixed> */
    private readonly array $json;

    /** @var array<string, mixed> */
    private readonly array $lock;

    public function __construct(private readonly ?string $composerJson, private readonly ?string $composerLock)
    {
        $this->json = self::decode($composerJson);
        $this->lock = self::decode($composerLock);
    }

    public function hasLockfile(): bool
    {
        return $this->composerLock !== null && $this->composerLock !== '';
    }

    public function json(): ?string
    {
        return $this->composerJson;
    }

    public function lock(): ?string
    {
        return $this->composerLock;
    }

    /**
     * The root `require` plus every locked package's — the whole dependency
     * graph's demands, flattened.
     *
     * @return list<array<string, mixed>>
     */
    public function requireMaps(): array
    {
        return $this->maps('require');
    }

    /**
     * Whether the project's **own** composer.json requires a package.
     *
     * Deliberately not {@see requireMaps()}, which flattens every locked
     * package's requirements too: half of Packagist depends on a symfony
     * component, and "depends on symfony/console" is not "is a Symfony
     * application".
     */
    public function rootRequires(string $package): bool
    {
        $require = $this->json['require'] ?? null;

        return is_array($require) && array_key_exists($package, $require);
    }

    /**
     * Whether a package is in the project at all: required at the root,
     * pinned in the lock, or listed in the installed tree's
     * `vendor/composer/installed.json` (Composer 1 or 2 shape).
     */
    public function resolves(string $package, ?string $installedJson = null): bool
    {
        if ($this->rootRequires($package)) {
            return true;
        }
        $installed = self::decode($installedJson);
        $installed = is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed;
        foreach ([...$this->lockedPackages(), ...array_filter($installed, 'is_array')] as $locked) {
            if (($locked['name'] ?? null) === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where Composer installs: `config.vendor-dir`, or `vendor`. A value
     * that could leave the project (absolute, `..`) reads as `vendor`.
     */
    public function vendorDir(): string
    {
        $config = $this->json['config'] ?? null;
        $dir = is_array($config) ? ($config['vendor-dir'] ?? null) : null;
        if (!is_string($dir) || str_starts_with($dir, '/')) {
            return 'vendor';
        }
        $dir = rtrim($dir, '/');
        if (preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $dir) !== 1
            || in_array('..', explode('/', $dir), true)
        ) {
            return 'vendor';
        }

        return $dir;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggestMaps(): array
    {
        return $this->maps('suggest');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maps(string $key): array
    {
        $maps = [];
        if (is_array($this->json[$key] ?? null)) {
            $maps[] = $this->json[$key];
        }
        foreach ($this->lockedPackages() as $package) {
            if (is_array($package[$key] ?? null)) {
                $maps[] = $package[$key];
            }
        }

        return $maps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lockedPackages(): array
    {
        $packages = $this->lock['packages'] ?? null;

        return is_array($packages) ? array_filter($packages, 'is_array') : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $raw): array
    {
        $decoded = $raw === null || $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
