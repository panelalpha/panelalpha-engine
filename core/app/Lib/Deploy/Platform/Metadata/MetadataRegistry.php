<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Every PackageMetadata reader: a class named `<Something>Metadata` in this directory. Ruby and Gradle are
 * absent, as both keep their name in executable code.
 */
final class MetadataRegistry
{
    /** @var array<string, PackageMetadata>|null */
    private static ?array $readers = null;

    /**
     * @return array<string, PackageMetadata> keyed by ecosystem id
     */
    public static function all(): array
    {
        if (self::$readers !== null) {
            return self::$readers;
        }

        $readers = [];
        foreach (scandir(__DIR__) ?: [] as $entry) {
            if (! str_ends_with($entry, 'Metadata.php') || $entry === 'PackageMetadata.php') {
                continue;
            }
            $class = __NAMESPACE__.'\\'.basename($entry, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, PackageMetadata::class)) {
                continue;
            }
            /** @var PackageMetadata $reader */
            $reader = new $class;
            $readers[$reader->id()] = $reader;
        }

        ksort($readers);

        return self::$readers = $readers;
    }

    /**
     * Every package file this project holds, one record per ecosystem present: polyglot is the normal case.
     *
     * @return list<AppPackage>
     */
    public static function read(ProjectContext $context): array
    {
        $packages = [];
        foreach (self::all() as $reader) {
            $package = $reader->read($context);
            if ($package !== null) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * The package file that best describes the application: the platform runtime's, else the first one a file
     * named rather than its directory, else the first record (the registry is sorted).
     *
     * @param  list<AppPackage>  $packages
     */
    public static function primary(array $packages, ?string $runtime = null): ?AppPackage
    {
        if ($packages === []) {
            return null;
        }

        if ($runtime !== null) {
            foreach ($packages as $package) {
                if ($package->ecosystem === $runtime) {
                    return $package;
                }
            }
        }

        foreach ($packages as $package) {
            if ($package->isNamed()) {
                return $package;
            }
        }

        return $packages[0];
    }

    /**
     * The metadata block of an inspection report, the primary package first so `packages[0]` is the application.
     *
     * @return array<string, mixed>
     */
    public static function describe(ProjectContext $context, ?string $runtime = null): array
    {
        $packages = self::read($context);
        $primary = self::primary($packages, $runtime);

        if ($primary !== null) {
            $packages = array_merge([$primary], array_values(array_filter(
                $packages,
                static fn (AppPackage $p): bool => $p !== $primary
            )));
        }

        return [
            'name' => $primary?->name,
            'description' => $primary?->description,
            'version' => $primary?->version,
            'framework' => $primary?->framework()?->toArray(),
            'source' => $primary?->file,
            'packages' => array_map(static fn (AppPackage $p): array => $p->toArray(), $packages),
        ];
    }

    /** Tests that install a fake reader need this. */
    public static function flush(): void
    {
        self::$readers = null;
    }
}
