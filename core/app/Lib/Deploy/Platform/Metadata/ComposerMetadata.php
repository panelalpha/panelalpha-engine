<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * PHP identity, from composer.json and composer.lock. `version` is normally absent and
 * that is correct — Composer asks projects not to declare it, as the tag is the version.
 * Framework versions come from the lock: `11.31.0` is what got installed.
 */
final class ComposerMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'composer.json';

    public const LOCK = 'composer.lock';

    /**
     * PHP frameworks worth naming, most specific first.
     *
     * A hand-kept list, because nothing derives it: Laravel is detected by its
     * `artisan` file and never mentions `laravel/framework`. Absence from this
     * list costs a project nothing beyond a nicer answer to "what is this".
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'laravel/framework' => 'Laravel',
        'symfony/framework-bundle' => 'Symfony',
        'symfony/symfony' => 'Symfony',
        'statamic/cms' => 'Statamic',
        'craftcms/cms' => 'Craft CMS',
        'drupal/core-recommended' => 'Drupal',
        'drupal/core' => 'Drupal',
        'typo3/cms-core' => 'TYPO3',
        'shopware/core' => 'Shopware',
        'magento/product-community-edition' => 'Magento',
        'pimcore/pimcore' => 'Pimcore',
        'october/rain' => 'October CMS',
        'cakephp/cakephp' => 'CakePHP',
        'yiisoft/yii2' => 'Yii',
        'codeigniter4/framework' => 'CodeIgniter',
        'laminas/laminas-mvc' => 'Laminas',
        'slim/slim' => 'Slim',
    ];

    public function id(): string
    {
        return 'php';
    }

    public function read(ProjectContext $context): ?AppPackage
    {
        if (! $context->isFile(self::FILE)) {
            return null;
        }
        $composer = self::map($context->composer());

        $require = self::map($composer['require'] ?? null);
        $requireDev = self::map($composer['require-dev'] ?? null);
        $name = self::text($composer['name'] ?? null);

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: $name ?? self::directoryName($context),
            nameSource: $name !== null ? self::FILE.' name' : AppPackage::NAME_FROM_DIRECTORY,
            description: self::text($composer['description'] ?? null),
            version: self::text($composer['version'] ?? null),
            license: implode(', ', self::stringList($composer['license'] ?? null)) ?: null,
            homepage: self::text($composer['homepage'] ?? null),
            repository: self::text($composer['support']['source'] ?? null),
            authors: self::stringList($composer['authors'] ?? null),
            keywords: self::stringList($composer['keywords'] ?? null),
            // Composer has no `private` flag; `type: project` is how it says this is an
            // application rather than something meant to be required by others.
            private: self::text($composer['type'] ?? null) === 'project',
            scripts: self::keys($composer['scripts'] ?? null),
            entrypoints: self::stringList($composer['bin'] ?? null),
            frameworks: self::matchFrameworks(
                self::FRAMEWORKS,
                self::constraints($require, $requireDev),
                $this->lockedVersions($context),
                self::FILE,
                self::LOCK
            ),
            dependencyCounts: [
                // Neither `php` nor an `ext-*` is a package anyone installed; counting them
                // would inflate every project by the width of its extension list.
                'require' => count(self::packagesOnly($require)),
                'require-dev' => count(self::packagesOnly($requireDev)),
            ],
            // The same entries the counts above exclude: what Composer calls the platform.
            // `require-dev` is not consulted — a dev-only `ext-xdebug` is not something the
            // built image has to satisfy.
            platform: self::platformOnly($require)
        );
    }

    /**
     * Installed versions from composer.lock, keyed by package.
     *
     * @return array<string, string>
     */
    private function lockedVersions(ProjectContext $context): array
    {
        $lock = self::map($context->composerLock());

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (self::map($lock[$section] ?? null) as $package) {
                if (! is_array($package)) {
                    continue;
                }
                $name = self::text($package['name'] ?? null);
                $version = self::text($package['version'] ?? null);
                if ($name === null || $version === null) {
                    continue;
                }
                // Composer writes tags as it found them, so the same release
                // is `v11.31.0` here and `11.31.0` in the next project.
                $versions[$name] = ltrim($version, 'vV');
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $require
     * @return array<string, mixed>
     */
    private static function packagesOnly(array $require): array
    {
        return array_filter(
            $require,
            static fn (string $name): bool => ! self::isPlatform($name),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The platform half of `require`, constraints kept as declared.
     *
     * @param  array<string, mixed>  $require
     * @return array<string, string>
     */
    private static function platformOnly(array $require): array
    {
        $platform = [];
        foreach ($require as $name => $constraint) {
            if (! is_string($name) || ! self::isPlatform($name)) {
                continue;
            }
            $platform[strtolower($name)] = self::text($constraint) ?? '*';
        }
        ksort($platform);

        return $platform;
    }

    /**
     * Copied from `Composer\Repository\PlatformRepository::PLATFORM_PACKAGE_REGEX`.
     *
     * Anchored and exhaustive on purpose: a prefix test reads `php-di/php-di` as the
     * runtime (matomo/matomo declares it) and misses `composer-runtime-api`, a platform
     * requirement no one installs.
     */
    private const PLATFORM_PACKAGE_REGEX =
        '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*'
        .'|composer-(?:plugin|runtime)-api)$}iD';

    private static function isPlatform(string $name): bool
    {
        return preg_match(self::PLATFORM_PACKAGE_REGEX, $name) === 1;
    }
}
