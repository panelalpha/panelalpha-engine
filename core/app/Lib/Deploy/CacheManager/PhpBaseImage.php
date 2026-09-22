<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Platform\Runtime\Php\PhpApacheConfig;
use App\Lib\Deploy\Platform\Runtime\Php\PhpIniDefaults;
use App\Lib\Deploy\Platform\Runtime\Php\PhpProxyHeaders;
use App\Lib\Deploy\Template\Template;
use App\Lib\Deploy\Template\TemplateLoader;

/**
 * A PanelAlpha-owned official-php derivative with the extensions almost every
 * PHP app asks for already compiled in.
 *
 * install-php-extensions builds from source whenever mlocati has no prebuilt
 * binary for the target PHP minor: on PHP 8.5 that is ~3 minutes of `make` per
 * account, for a byte-identical result every time.
 *
 * The tag carries a fingerprint of the baked set, so editing {@see EXTENSIONS}
 * builds a new image instead of serving a stale one.
 */
class PhpBaseImage
{
    /**
     * Where the engine puts this image. Null when the catalogue describes no
     * PHP build, so the host serves the stock php image and the account
     * compiles its own extensions.
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

    /**
     * The port the baked vhost listens on. The application's own manifest
     * declares the same number, so it is spelled once.
     */
    public const PORT = 8000;

    /** Where the account's composer cache is mounted, when one is. */
    public const COMPOSER_CACHE_DIR = '/var/cache/pa-composer';

    /** The shim that makes the image runnable with nothing but a bind mount. */
    public const ENTRYPOINT_PATH = '/usr/local/bin/panelalpha-entrypoint';

    public const ENTRYPOINT_ASSET = 'panelalpha-base-entrypoint.sh';

    /**
     * Turns a document root into a running server, so a manifest can declare
     * `docroot:` instead of a shell snippet.
     */
    public const SERVE_PATH = '/usr/local/bin/panelalpha-serve';

    public const SERVE_ASSET = 'panelalpha-serve.sh';

    /**
     * What a checkout with no front controller is served from. Empty, and
     * outside /app so the account cannot put anything in it -- the previous
     * fallback published the whole source tree with the PHP handler over it.
     * {@see SERVE_ASSET} spells the same path; PhpServeFallbackTest pins them
     * together.
     */
    public const EMPTY_DOCROOT = '/usr/local/lib/panelalpha/empty-docroot';

    /**
     * Everything a deployed PHP application is served with. No per-project
     * Dockerfile installs what is missing, so an extension absent here is
     * absent from the running site — a working deploy serving a 500.
     *
     * mysqli, ftp, soap and xsl because the shipped manifests ask for them:
     * WordPress, Matomo, phpMyAdmin and Adminer pick mysqli, Magento requires
     * the other three. memcached because ownCloud requires it outright. The
     * rest are what applications commonly *suggest*
     * ({@see PhpExtensions::SUGGESTABLE}) plus the caches and session backends
     * a hosting account is expected to have.
     *
     * Each costs host build time once and every account's disk quota after, so
     * anything added has to be checked against a real application asking for
     * it.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        // symfony/amqp-messenger hard-requires ext-amqp, and Symfony
        // distributions pull it in with the Messenger transport set whether or
        // not they ever speak to a broker -- pimcore/skeleton does. Without it
        // Composer refuses to resolve at all, so the deploy dies in the host
        // build naming an extension rather than anything the user can act on.
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

    /**
     * Every distinct extras set is another ~800 MB image, so beyond this many
     * extensions there is no variant and the app compiles them in its own
     * build.
     */
    public const MAX_BAKED_EXTRAS = 4;

    /**
     * Null when the image is not a plain official php tag: a custom base is
     * nothing we can prebuild, so the caller keeps the stock path.
     *
     * $extras — extensions needed on top of {@see EXTENSIONS} — get their own
     * suffix, so a variant is a distinct cacheable image.
     *
     * @param list<string> $extras
     */
    public static function tag(string $phpImage, array $extras = []): ?string
    {
        $repository = self::repository();
        $upstream = self::upstreamRepository();
        $fingerprint = self::fingerprint();
        // Nothing to build, so nothing to name. A tag the catalogue cannot
        // recognise back would be built, not identified as ours, and taken to
        // a registry that never published it.
        if ($repository === null || $upstream === null || $fingerprint === null
            || self::stubName() === null
        ) {
            return null;
        }

        if (preg_match('#^' . preg_quote($upstream, '#') . ':([a-z0-9._-]+)$#i', trim($phpImage), $matches) !== 1) {
            return null;
        }

        $tag = $repository . ':' . $matches[1] . '-pa' . $fingerprint;
        $extras = self::normalizeExtras($extras);

        return $extras === [] ? $tag : $tag . '-x' . self::extrasFingerprint($extras);
    }

    /**
     * What a fresh build would be tagged with: the `recipe` date the catalogue
     * declares, as `Ymd`. Null when it declares none.
     *
     * A date, not a hash of the recipe, so it does not maintain itself: change
     * {@see EXTENSIONS}, the stub or the entrypoint without bumping the date
     * and every host holding the old image keeps serving it under the new name
     * — same tag, different contents.
     */
    public static function fingerprint(): ?string
    {
        return RuntimeImageCatalog::recipeDate('php');
    }

    /**
     * Sorted and de-duplicated, so the same set resolves to the same tag
     * whatever order a project's requires were read in.
     *
     * @param list<string> $extras
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
     * @param list<string> $extras
     */
    public static function extrasFingerprint(array $extras): string
    {
        return substr(sha1(implode(' ', self::normalizeExtras($extras))), 0, 8);
    }

    /**
     * The subset of $extras worth baking into a host image, or an empty list
     * when this app should just compile them itself.
     *
     * @param list<string> $extras
     * @return list<string>
     */
    public static function bakeableExtras(array $extras): array
    {
        $extras = self::normalizeExtras($extras);

        return count($extras) > self::MAX_BAKED_EXTRAS ? [] : $extras;
    }

    /**
     * The official php tag this was built from, so a host that pruned the
     * image can rebuild it. Null for anything that is not one of ours —
     * getting that wrong sends the tag to a registry that never published it.
     */
    public static function sourceImage(string $tag): ?string
    {
        $source = BuiltImage::sourceImage($tag);

        return $source !== null && BuiltImage::runtimeFor($tag) === 'php' ? $source : null;
    }

    /**
     * Extensions an app needs on top of the baked set. $baked are extras
     * already compiled into the variant base the app will build FROM, so they
     * drop out here instead of being installed twice.
     *
     * @param list<string> $required
     * @param list<string> $baked
     * @return list<string>
     */
    public static function missingExtensions(array $required, array $baked = []): array
    {
        $alreadyPresent = array_merge(self::EXTENSIONS, self::normalizeExtras($baked));
        $missing = [];
        foreach ($required as $name) {
            if (is_string($name) && $name !== '' && !in_array(strtolower($name), $alreadyPresent, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Extras get their own layer, so two apps needing different ones share
     * every layer up to the baked set.
     *
     * @param list<string> $extras
     */
    public static function dockerfile(string $phpImage, array $extras = []): ?string
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
