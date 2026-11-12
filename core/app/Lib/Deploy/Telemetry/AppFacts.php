<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\Framework;

/**
 * The detected application metadata a report may transmit: third-party facts travel at every tier, the customer's
 * own name and version follow the repository rule (clear when public, a salted hash when private).
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

        $app = self::stack($primary) + self::presentSections($primary, $packages);

        if ($tier >= DeployReport::TIER_REPO) {
            $app += self::identity($primary, $private, $installId);
        }

        return $app;
    }

    /**
     * Never the description, authors, keywords, homepage, repository URL, licence, scripts, entrypoints or
     * workspace members. `named` says a file declared the name, not the folder standing in for one.
     *
     * @return array<string, mixed>
     */
    private static function stack(AppPackage $primary): array
    {
        return [
            'ecosystem' => $primary->ecosystem,
            'file' => $primary->file,
            'named' => $primary->isNamed(),
            'frameworks' => self::frameworks($primary->frameworks),
        ];
    }

    /**
     * Omitted when empty: PHP encodes an empty array as `[]`, a JSON list where the ingest expects an object.
     *
     * @param  list<AppPackage>  $packages
     * @return array<string, mixed>
     */
    private static function presentSections(AppPackage $primary, array $packages): array
    {
        $sections = [];
        $platform = self::platform($primary->platform);
        if ($platform !== []) {
            $sections['platform'] = $platform;
        }

        $counts = self::counts($primary->dependencyCounts);
        if ($counts !== []) {
            $sections['dependencies'] = $counts;
        }

        $also = self::also($packages);
        if ($also !== []) {
            $sections['also'] = $also;
        }

        return $sections;
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
            return ['name_hash' => self::nameHash($installId, $package->ecosystem, $package->name)];
        }

        $identity = ['name' => $package->name];
        if ($package->version !== null) {
            $identity['version'] = $package->version;
        }

        return $identity;
    }

    /** A private package's name, salted per install as a repository path is; a bug report hashes it the same way. */
    public static function nameHash(string $installId, string $ecosystem, string $name): string
    {
        return substr(hash('sha256', $installId.'|'.$ecosystem.'|'.$name), 0, 16);
    }

    /**
     * `id` is the package name, `name` saves the receiver a lookup table; `constraint` is what the project
     * asked for, `version` what the lockfile pinned.
     *
     * @param  list<Framework>  $frameworks
     * @return list<array<string, mixed>>
     */
    private static function frameworks(array $frameworks): array
    {
        $reported = [];
        foreach (array_slice($frameworks, 0, self::MAX_FRAMEWORKS) as $framework) {
            $reported[] = [
                'id' => $framework->id,
                'name' => $framework->name,
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
     * The other ecosystems present, named but not described: which halves a project has (a Laravel app with
     * a Vite front end) changes what a failed build means.
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
