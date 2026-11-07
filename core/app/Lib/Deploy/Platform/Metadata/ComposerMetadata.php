<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * PHP identity, from composer.json and composer.lock: `version` is normally absent, as the tag is the version,
 * and framework versions come from the lock.
 */
final class ComposerMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'composer.json';

    public const LOCK = 'composer.lock';

    /**
     * PHP frameworks worth naming, most specific first; hand-kept, as detection never reads `laravel/framework`.
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
            private: self::isApplication($composer),
            scripts: self::keys($composer['scripts'] ?? null),
            entrypoints: self::stringList($composer['bin'] ?? null),
            frameworks: $this->frameworks($context, $require, $requireDev),
            dependencyCounts: self::dependencyCounts($require, $requireDev),
            platform: self::platformOnly($require)
        );
    }

    /**
     * Composer has no `private` flag; `type: project` is how it says this is an application, not a library.
     *
     * @param  array<string, mixed>  $composer
     */
    private static function isApplication(array $composer): bool
    {
        return self::text($composer['type'] ?? null) === 'project';
    }

    /**
     * @param  array<string, mixed>  $require
     * @param  array<string, mixed>  $requireDev
     * @return list<Framework>
     */
    private function frameworks(ProjectContext $context, array $require, array $requireDev): array
    {
        return self::matchFrameworks(
            self::FRAMEWORKS,
            self::constraints($require, $requireDev),
            $this->lockedVersions($context),
            self::FILE,
            self::LOCK
        );
    }

    /**
     * Neither `php` nor an `ext-*` is a package anyone installed; counting them would inflate every project.
     *
     * @param  array<string, mixed>  $require
     * @param  array<string, mixed>  $requireDev
     * @return array<string, int>
     */
    private static function dependencyCounts(array $require, array $requireDev): array
    {
        return [
            'require' => count(self::packagesOnly($require)),
            'require-dev' => count(self::packagesOnly($requireDev)),
        ];
    }

    /**
     * Installed versions from composer.lock, keyed by package, with the `v` a tag may carry dropped.
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
     * The platform half of `require`, constraints kept as declared; `require-dev` is not consulted, as a dev-only
     * `ext-xdebug` is nothing the built image has to satisfy.
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
     * Copied from `Composer\Repository\PlatformRepository::PLATFORM_PACKAGE_REGEX`; anchored, as a prefix test
     * reads `php-di/php-di` as the runtime and misses `composer-runtime-api`.
     */
    private const PLATFORM_PACKAGE_REGEX =
        '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*'
        .'|composer-(?:plugin|runtime)-api)$}iD';

    private static function isPlatform(string $name): bool
    {
        return preg_match(self::PLATFORM_PACKAGE_REGEX, $name) === 1;
    }
}
