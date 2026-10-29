<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\Framework;

/**
 * The detected application metadata a report may transmit. The split is by who
 * owns the string: third-party facts (`laravel/framework@11.9`, `php: ^8.2`,
 * `ext-gd`) travel at every tier, the customer's own name and version follow the
 * repository rule — in the clear for a public repo, a salted hash for a private one.
 */
final class AppFacts
{
    /** Frameworks reported. A project declaring more is declaring noise. */
    public const MAX_FRAMEWORKS = 3;

    /** Extensions reported, after the runtime keys, which are always kept. */
    public const MAX_EXTENSIONS = 20;

    /** Other ecosystems named alongside the primary one. */
    public const MAX_ALSO = 4;

    /**
     * @param  list<AppPackage>  $packages  primary first
     * @return array<string, mixed> empty when nothing named this application
     */
    public static function build(
        array $packages,
        int $tier,
        bool $private,
        string $installId
    ): array {
        $primary = $packages[0] ?? null;
        if (! $primary instanceof AppPackage) {
            return [];
        }

        // Never projected at any tier: description, authors, keywords, homepage,
        // repository URL, licence, script names, entrypoints, workspace members.
        $app = [
            'ecosystem' => $primary->ecosystem,
            'file' => $primary->file,
            // A file declared this name, not the folder standing in for one.
            'named' => $primary->isNamed(),
            'frameworks' => self::frameworks($primary->frameworks),
        ];

        // Omitted when empty: PHP encodes an empty array as `[]`, so a JSON array
        // would arrive where the ingest expects an object. `frameworks` above is a
        // list either way, so `[]` is right for it.
        $platform = self::platform($primary->platform);
        if ($platform !== []) {
            $app['platform'] = $platform;
        }

        $counts = self::counts($primary->dependencyCounts);
        if ($counts !== []) {
            $app['dependencies'] = $counts;
        }

        $also = self::also($packages);
        if ($also !== []) {
            $app['also'] = $also;
        }

        if ($tier >= DeployReport::TIER_REPO) {
            $app += self::identity($primary, $private, $installId);
        }

        return $app;
    }

    /**
     * The customer-owned half: name and version. A directory-derived name is
     * dropped — hashing the account's project folder groups nothing.
     *
     * @return array<string, mixed>
     */
    private static function identity(AppPackage $package, bool $private, string $installId): array
    {
        if (! $package->isNamed() || $package->name === null) {
            return [];
        }

        if ($private) {
            return ['name_hash' => self::hash($installId, $package->ecosystem, $package->name)];
        }

        $identity = ['name' => $package->name];
        if ($package->version !== null) {
            $identity['version'] = $package->version;
        }

        return $identity;
    }

    /** Salted per install, exactly as a private repository path is. */
    private static function hash(string $installId, string $ecosystem, string $name): string
    {
        return substr(hash('sha256', $installId.'|'.$ecosystem.'|'.$name), 0, 16);
    }

    /**
     * @param  list<Framework>  $frameworks
     * @return list<array<string, mixed>>
     */
    private static function frameworks(array $frameworks): array
    {
        $reported = [];
        foreach (array_slice($frameworks, 0, self::MAX_FRAMEWORKS) as $framework) {
            $reported[] = [
                // The package name is the identifier; the display name saves the
                // receiver its own lookup table.
                'id' => $framework->id,
                'name' => $framework->name,
                // Both kept: `^11.0` is what the project asked for, 11.9.2 what
                // the lockfile pinned.
                'constraint' => $framework->constraint,
                'version' => $framework->version,
                'major' => $framework->major(),
            ];
        }

        return $reported;
    }

    /**
     * Runtime requirements in full, extensions capped: the runtime constraint is
     * always the interesting one, so the `ext-*`/`lib-*` list is what gets trimmed.
     *
     * @param  array<string, string>  $platform
     * @return array<string, string>
     */
    private static function platform(array $platform): array
    {
        $runtime = [];
        $extensions = [];
        foreach ($platform as $name => $constraint) {
            if (! is_string($name) || ! is_string($constraint)) {
                continue;
            }
            if (str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                $extensions[$name] = $constraint;

                continue;
            }
            $runtime[$name] = $constraint;
        }

        ksort($runtime);
        ksort($extensions);

        return $runtime + array_slice($extensions, 0, self::MAX_EXTENSIONS, true);
    }

    /**
     * Dependency counts, integers only.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function counts(array $counts): array
    {
        $clean = [];
        foreach ($counts as $section => $count) {
            if (is_string($section) && is_int($count)) {
                $clean[$section] = $count;
            }
        }
        ksort($clean);

        return $clean;
    }

    /**
     * The other ecosystems present, named but not described: a Laravel app with a
     * Vite front end is a composer.json and a package.json, and which halves a
     * project has changes what a failed build means.
     *
     * @param  list<AppPackage>  $packages
     * @return list<string>
     */
    private static function also(array $packages): array
    {
        $others = [];
        foreach (array_slice($packages, 1) as $package) {
            if ($package instanceof AppPackage && ! in_array($package->ecosystem, $others, true)) {
                $others[] = $package->ecosystem;
            }
        }
        sort($others);

        return array_slice($others, 0, self::MAX_ALSO);
    }
}
