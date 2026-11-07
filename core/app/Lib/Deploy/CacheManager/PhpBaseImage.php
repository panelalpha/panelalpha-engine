<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\Php\PhpApacheConfig;
use App\Lib\Deploy\Platform\Runtime\Php\PhpIniDefaults;
use App\Lib\Deploy\Platform\Runtime\Php\PhpProxyHeaders;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Template\Template;
use App\Lib\Deploy\Template\TemplateLoader;

/**
 * A PanelAlpha-owned official-php derivative with the extensions almost every PHP app asks for
 * compiled in once, instead of ~3 minutes of `make` per account where no prebuilt binary exists.
 */
class PhpBaseImage
{
    /**
     * Where the engine puts this image. Null when the catalogue describes no PHP build, so the
     * host serves the stock php image and the account compiles its own extensions.
     */
    public static function repository(): ?string
    {
        return RuntimeImageCatalog::repository('php');
    }

    /** The Dockerfile stub it is built from. */
    public static function stubName(): ?string
    {
        return RuntimeImageCatalog::stub('php');
    }

    /** The official image family it is built from — `php` of `php:8.3-apache-bookworm`. */
    private static function upstreamRepository(): ?string
    {
        return RuntimeImageCatalog::upstreamRepository('php');
    }

    /** The port the baked vhost listens on; the application's own manifest declares the same number. */
    public const PORT = 8000;

    /** Where the account's composer cache is mounted, when one is. */
    public const COMPOSER_CACHE_DIR = '/var/cache/pa-composer';

    /** The shim that makes the image runnable with nothing but a bind mount. */
    public const ENTRYPOINT_PATH = '/usr/local/bin/panelalpha-entrypoint';

    public const ENTRYPOINT_ASSET = 'panelalpha-base-entrypoint.sh';

    /** Turns a document root into a running server, so a manifest can declare `docroot:` instead of a script. */
    public const SERVE_PATH = '/usr/local/bin/panelalpha-serve';

    public const SERVE_ASSET = 'panelalpha-serve.sh';

    /**
     * What a checkout with no front controller is served from: empty, and outside /app so the account
     * cannot put anything in it. {@see SERVE_ASSET} spells the same path; PhpServeFallbackTest pins them.
     */
    public const EMPTY_DOCROOT = '/usr/local/lib/panelalpha/empty-docroot';

    /**
     * Everything a deployed PHP application is served with; an extension absent here is absent from the
     * running site. amqp because Symfony's Messenger set hard-requires it, or Composer refuses to resolve.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        'amqp',
        'apcu',
        'bcmath',
        'calendar',
        'exif',
        'ftp',
        'gd',
        'gettext',
        'gmp',
        'gnupg',
        'igbinary',
        'imagick',
        'intl',
        'ldap',
        'memcached',
        'mysqli',
        'opcache',
        'pcntl',
        'pdo_mysql',
        'pdo_pgsql',
        'pdo_sqlite',
        'pgsql',
        'redis',
        'soap',
        'sockets',
        'sysvsem',
        'xsl',
        'zip',
    ];

    /** Every distinct extras set is another ~800 MB image, so beyond this many the app compiles them itself. */
    public const MAX_BAKED_EXTRAS = 4;

    /**
     * Debian packages a manifest may add with `system_packages:`, each set its own `-x` variant.
     * An allowlist, because every distinct set is another ~1.1 GB image on the host.
     *
     * @var list<string>
     */
    public const SYSTEM_PACKAGES = ['ffmpeg', 'mediainfo'];

    /**
     * Null when the image is not a plain official php tag, or when the catalogue could not recognise the
     * tag back. $extras and $packages go into one `-x` suffix, so a variant is a distinct cacheable image.
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
     */
    public static function tag(string $phpImage, array $extras = [], array $packages = []): ?string
    {
        $repository = self::repository();
        $upstream = self::upstreamRepository();
        $fingerprint = self::fingerprint();
        if ($repository === null || $upstream === null || $fingerprint === null
            || self::stubName() === null
        ) {
            return null;
        }

        if (preg_match('#^'.preg_quote($upstream, '#').':([a-z0-9._-]+)$#i', trim($phpImage), $matches) !== 1) {
            return null;
        }

        $tag = $repository.':'.$matches[1].'-pa'.$fingerprint;
        $extras = self::normalizeExtras($extras);
        $packages = self::normalizeSystemPackages($packages);

        return $extras === [] && $packages === []
            ? $tag
            : $tag.'-x'.self::variantFingerprint($extras, $packages);
    }

    /**
     * The catalogue's `recipe` date as `Ymd`, or null. A date, not a hash: change the recipe without
     * bumping it and every host keeps serving the old image under the new name.
     */
    public static function fingerprint(): ?string
    {
        return RuntimeImageCatalog::recipeDate('php');
    }

    /**
     * Sorted and de-duplicated, so the same set resolves to the same tag whatever the order.
     *
     * @param  list<string>  $extras
     * @return list<string>
     */
    public static function normalizeExtras(array $extras): array
    {
        $clean = [];
        foreach ($extras as $name) {
            if (is_string($name) && preg_match('/^[a-z0-9_]+$/i', $name) === 1) {
                $clean[strtolower($name)] = true;
            }
        }
        $names = array_keys($clean);
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $extras
     */
    public static function extrasFingerprint(array $extras): string
    {
        return substr(sha1(implode(' ', self::normalizeExtras($extras))), 0, 8);
    }

    /**
     * The extras-only hash is kept as it was, so every `-x` image a host holds keeps its name.
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
     */
    public static function variantFingerprint(array $extras, array $packages = []): string
    {
        $packages = self::normalizeSystemPackages($packages);
        if ($packages === []) {
            return self::extrasFingerprint($extras);
        }

        return substr(sha1(implode(' ', self::normalizeExtras($extras)).' +apt '.implode(' ', $packages)), 0, 8);
    }

    /**
     * Allowlisted, sorted and de-duplicated; the manifest already refuses anything else at load.
     *
     * @param  list<string>  $packages
     * @return list<string>
     */
    public static function normalizeSystemPackages(array $packages): array
    {
        $clean = [];
        foreach ($packages as $name) {
            if (is_string($name) && in_array(strtolower(trim($name)), self::SYSTEM_PACKAGES, true)) {
                $clean[strtolower(trim($name))] = true;
            }
        }
        $names = array_keys($clean);
        sort($names);

        return $names;
    }

    /**
     * The subset of $extras worth baking into a host image, or none when the app compiles them itself.
     *
     * @param  list<string>  $extras
     * @return list<string>
     */
    public static function bakeableExtras(array $extras): array
    {
        $extras = self::normalizeExtras($extras);

        return count($extras) > self::MAX_BAKED_EXTRAS ? [] : $extras;
    }

    /**
     * The official php tag this was built from, so a host that pruned the image can rebuild it.
     * Null for anything that is not one of ours.
     */
    public static function sourceImage(string $tag): ?string
    {
        $source = BuiltImage::sourceImage($tag);

        return $source !== null && BuiltImage::runtimeFor($tag) === 'php' ? $source : null;
    }

    /**
     * Extensions an app needs on top of the baked set and the $baked extras of its variant base.
     *
     * @param  list<string>  $required
     * @param  list<string>  $baked
     * @return list<string>
     */
    public static function missingExtensions(array $required, array $baked = []): array
    {
        $alreadyPresent = array_merge(self::EXTENSIONS, self::normalizeExtras($baked));
        $missing = [];
        foreach ($required as $name) {
            if (is_string($name) && $name !== '' && ! in_array(strtolower($name), $alreadyPresent, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Extras get their own layer, so two apps needing different ones share every layer up to the baked set.
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
     */
    public static function dockerfile(string $phpImage, array $extras = [], array $packages = []): ?string
    {
        $stub = self::stubName();
        if ($stub === null) {
            return null;
        }

        return Template::named($stub)->render([
            'php_image' => $phpImage,
            'installer_image' => Images::PHP_EXTENSION_INSTALLER_IMAGE,
            'extensions' => implode(' ', self::EXTENSIONS),
            'extras' => implode(' ', self::normalizeExtras($extras)),
            'system_packages' => implode(' ', self::normalizeSystemPackages($packages)),
            'composer_image' => Images::COMPOSER_IMAGE,
            'composer_cache_dir' => self::COMPOSER_CACHE_DIR,
            'apache_modules' => PhpApacheConfig::modules(),
            'ports_path' => PhpApacheConfig::PORTS_PATH,
            'ports_conf' => PhpApacheConfig::ports(self::PORT),
            'vhost_path' => PhpApacheConfig::VHOST_PATH,
            'vhost_conf' => PhpApacheConfig::vhost(self::PORT),
            'php_ini_path' => PhpIniDefaults::INI_PATH,
            'php_ini' => PhpIniDefaults::ini(),
            'proxy_script_dir' => PhpProxyHeaders::IMAGE_DIR,
            'proxy_script_path' => PhpProxyHeaders::IMAGE_PATH,
            'proxy_script' => rtrim(PhpProxyHeaders::script()),
            'proxy_ini_path' => PhpProxyHeaders::INI_PATH,
            'proxy_ini' => PhpProxyHeaders::ini(PhpProxyHeaders::IMAGE_PATH),
            'empty_docroot' => self::EMPTY_DOCROOT,
            'serve_path' => self::SERVE_PATH,
            'serve_script' => rtrim(TemplateLoader::asset(self::SERVE_ASSET)),
            'entrypoint_path' => self::ENTRYPOINT_PATH,
            'entrypoint_script' => rtrim(TemplateLoader::asset(self::ENTRYPOINT_ASSET)),
            'port' => self::PORT,
        ]);
    }
}
