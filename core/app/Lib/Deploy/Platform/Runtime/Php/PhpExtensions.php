<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * The `ext-*` a project needs compiled into its image, read from composer's require graph so an
 * app the engine has never seen still gets what it declared.
 */
final class PhpExtensions
{
    /**
     * Built into php:*-cli, so never installed again; check each name against `php -m` in the image,
     * not memory (`ftp` is not built in, `uri` is core from PHP 8.5).
     *
     * @var list<string>
     */
    private const BUNDLED = [
        'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash',
        'iconv', 'json', 'libxml', 'mbstring', 'mysqlnd', 'openssl', 'pcre',
        'pdo', 'phar', 'posix', 'readline', 'reflection', 'session',
        'simplexml', 'sodium', 'spl', 'standard', 'tokenizer', 'uri', 'xml',
        'xmlreader', 'xmlwriter', 'zlib',
    ];

    /**
     * Installed when merely suggested, since packages often only *suggest* gd/imagick/redis.
     *
     * @var list<string>
     */
    private const SUGGESTABLE = [
        'bcmath', 'exif', 'gd', 'gmp', 'imagick', 'intl', 'pcntl', 'redis',
        'soap', 'xsl', 'zip',
    ];

    private const PREFIX = 'ext-';

    private const NAME_PATTERN = '/^[a-z0-9_]+$/';

    public function __construct(
        private readonly ComposerManifest $composer,
        private readonly bool $artisan = true,
        private readonly bool $needsMysql = false
    ) {}

    /**
     * @return list<string>
     */
    public static function for(ComposerManifest $composer, bool $artisan = true, bool $needsMysql = false): array
    {
        return (new self($composer, $artisan, $needsMysql))->all();
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $names = array_merge($this->databaseDriver(), $this->required(), $this->suggested());
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Both MySQL drivers when there is a database, since the application picks its own and its
     * require graph may name neither (WordPress only knows mysqli).
     *
     * @return list<string>
     */
    private function databaseDriver(): array
    {
        if ($this->needsMysql) {
            return ['mysqli', 'pdo_mysql'];
        }

        return $this->artisan ? ['pdo_sqlite'] : [];
    }

    /**
     * The extensions the project *requires*, not merely suggests: a base image lacking one fails
     * `composer install` outright, so a variant built for it may not wait.
     *
     * @return list<string>
     */
    public static function requiredFor(ComposerManifest $composer): array
    {
        $names = array_values(array_unique((new self($composer))->required()));
        sort($names);

        return $names;
    }

    /**
     * Bare extension names with the ones PHP already bundles removed, so an image catalogue
     * `extensions:` list does not describe a variant no deploy can ask for.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function installable(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = strtolower($name);
            if (preg_match(self::NAME_PATTERN, $name) === 1 && ! in_array($name, self::BUNDLED, true)) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function required(): array
    {
        return $this->fromMaps($this->composer->requireMaps());
    }

    /**
     * @return list<string>
     */
    private function suggested(): array
    {
        return array_values(array_intersect($this->fromMaps($this->composer->suggestMaps()), self::SUGGESTABLE));
    }

    /**
     * @param  list<array<string, mixed>>  $maps
     * @return list<string>
     */
    private function fromMaps(array $maps): array
    {
        $names = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $package) {
                $name = self::extensionName((string) $package);
                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private static function extensionName(string $package): ?string
    {
        if (! str_starts_with($package, self::PREFIX)) {
            return null;
        }
        $name = strtolower(substr($package, strlen(self::PREFIX)));
        $known = preg_match(self::NAME_PATTERN, $name) === 1 && ! in_array($name, self::BUNDLED, true);

        return $known ? $name : null;
    }
}
