<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\ProjectCache;

/**
 * A PHP project's build on the host: `composer install` in a throwaway container from the shared base the app is
 * served from, so the PHP minor and extensions are real, writing `vendor/` into the bind-mounted `~/project`.
 */
final class PhpHostBuild
{
    /**
     * Scripts and plugins run customer PHP on the host daemon, so both are off; `--no-plugins` goes only under
     * the runtime manifest's {@see allowPlugins()}, since for Drupal and the like the plugin is the installer.
     */
    public const SAFE_INSTALL_FLAGS = '--no-dev --no-interaction --no-scripts --no-plugins';

    /**
     * The plugins the host install lets Composer run, by package name: they write files a project loads
     * (symfony/runtime's `autoload_runtime.php`); a plugin running a build belongs to the manifest's steps.
     *
     * @var list<string>
     */
    public const INSTALLER_PLUGINS = [
        'composer/installers',
        'drupal/core-composer-scaffold',
        'drupal/core-recipe-unpack',
        'drupal/core-project-message',
        'symfony/runtime',
    ];

    /**
     * Each installer allowed, then a last `"*": false`: an unmentioned plugin throws PluginBlockedException
     * under --no-interaction. It replaces the project's own list, so `"*": true` there re-enables nothing.
     *
     * @return array<string, bool>
     */
    public static function allowPlugins(): array
    {
        $rules = array_fill_keys(self::INSTALLER_PLUGINS, true);
        $rules['*'] = false;

        return $rules;
    }

    /**
     * The install with `--no-plugins` dropped under {@see allowPlugins()}; a manifest's own `composer install`
     * is otherwise untouched.
     */
    public static function installCommand(string $install, bool $pluginsRestricted): string
    {
        if ($install === '' || ! $pluginsRestricted) {
            return $install;
        }

        return trim((string) preg_replace('/\s*--no-plugins\b/', '', $install));
    }

    /**
     * The dependency step when a manifest declares none, as most do; without it they deployed with no `vendor/`.
     * `--optimize-autoloader` because nothing dumps the autoloader afterwards unless the manifest says so.
     */
    public const DEFAULT_INSTALL = 'composer install '.self::SAFE_INSTALL_FLAGS.' --optimize-autoloader';

    /**
     * The manifest copy Composer resolves from, carrying what does not belong in the customer's composer.json,
     * beside it inside the mount so the build container and the account see one file.
     *
     * @see runtimeManifest()
     */
    public const RUNTIME_MANIFEST_FILE = '.pa-runtime-composer.json';

    /** Reserved for a copy of the client's `composer.lock` beside {@see RUNTIME_MANIFEST_FILE} (ADR-0001). */
    public const RUNTIME_LOCK_FILE = '.pa-runtime-composer.lock';

    /**
     * Points Composer at a manifest other than composer.json; a `-e` on the container's argv, so it reaches every
     * invocation in the step without shell quoting.
     */
    public const MANIFEST_ENV = 'COMPOSER';

    /**
     * `composer config platform.php <minor>.99` (any patch), shared with {@see DindHostBuilder::composerInstallArgv()},
     * written into the manifest COMPOSER names; '' unless shaped like a minor, as it reaches a shell.
     */
    public static function platformPin(?string $phpVersion): string
    {
        if ($phpVersion === null || preg_match('/^\d+\.\d+$/', $phpVersion) !== 1) {
            return '';
        }

        return 'composer config --no-plugins platform.php '.$phpVersion.'.99';
    }

    /**
     * What the deploy log says when the lock contradicts itself, about the project: its lock is out of date.
     */
    public static function lockContradictionNote(): string
    {
        return 'echo "[panelalpha] this project\'s composer.lock pins a PHP its own '
            .'packages reject, so the locked set is installed as-is; php is the one '
            .'requirement not enforced for it" >&2';
    }

    /**
     * composer.json with allow-plugins replaced by {@see allowPlugins()}, and without a lock (when `require` exists)
     * no require-dev, whose advisory-pruned pins block installs; decoded as objects so `{}` and lists round-trip.
     */
    public static function runtimeManifest(string $composerJson, ?string $composerLock = null): ?string
    {
        $decoded = json_decode($composerJson);
        if (! is_object($decoded)) {
            return null;
        }

        $locked = $composerLock !== null && trim($composerLock) !== '';
        if (! $locked && isset($decoded->{'require-dev'}) && isset($decoded->require) && is_object($decoded->require)) {
            unset($decoded->{'require-dev'});
        }

        if (! isset($decoded->config) || ! is_object($decoded->config)) {
            $decoded->config = new \stdClass;
        }
        $decoded->config->{'allow-plugins'} = (object) self::allowPlugins();

        $encoded = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $encoded === false ? null : $encoded;
    }

    /**
     * Whether a lockless build with nothing dropped copies its {@see RUNTIME_LOCK_FILE} back to composer.lock,
     * which container steps install from (contao).
     */
    public static function mirrorsRuntimeLock(string $composerJson, ?string $composerLock): bool
    {
        if ($composerLock !== null && trim($composerLock) !== '') {
            return false;
        }
        $decoded = json_decode($composerJson);

        return is_object($decoded)
            && ! (isset($decoded->{'require-dev'}) && isset($decoded->require) && is_object($decoded->require));
    }

    /**
     * The host's Composer cache for this project, beside the JS ones under its own directory. {@see ProjectCache}
     */
    public static function cacheDirFor(string $username): string
    {
        return ProjectCache::subdirFor($username, ProjectCache::COMPOSER);
    }

    /**
     * Where the running container caches Composer: under the account's home, the one path both daemons see,
     * which survives the `git clone` that wipes `~/project`; not {@see cacheDirFor()}.
     */
    public static function accountCacheDirFor(string $homeDir): string
    {
        $home = rtrim(trim($homeDir), '/');
        if (
            preg_match('#^(/[a-zA-Z0-9_.-]+)+\z#', $home) !== 1
            || str_contains($home, '/../')
            || str_ends_with($home, '/..')
        ) {
            throw new \InvalidArgumentException('Invalid account home directory for composer cache');
        }

        return $home.'/.cache/composer';
    }

    /**
     * Creates the classmap directories a checkout lacks, on which the autoload dump throws (ILIAS), or ''.
     * Only plain relative directory names, never the vendor dir.
     */
    public static function classmapDirsStep(?string $composerJson): string
    {
        $decoded = json_decode((string) $composerJson, true);
        $classmap = is_array($decoded) ? ($decoded['autoload']['classmap'] ?? null) : null;
        if (! is_array($classmap)) {
            return '';
        }
        $vendor = $decoded['config']['vendor-dir'] ?? 'vendor';
        $vendor = is_string($vendor) ? trim((string) preg_replace('#^(\./)+#', '', $vendor), '/') : 'vendor';

        $dirs = [];
        foreach ($classmap as $path) {
            if (! is_string($path)) {
                continue;
            }
            $path = trim((string) preg_replace('#^(\./)+#', '', trim($path)), '/');
            if ($path === ''
                || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $path) !== 1
                || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
                || str_contains(basename($path), '.')
                || $path === $vendor
                || str_starts_with($path, $vendor.'/')
            ) {
                continue;
            }
            $dirs[] = escapeshellarg($path);
        }
        if ($dirs === []) {
            return '';
        }

        return 'for d in '.implode(' ', array_unique($dirs)).'; do [ -e "$d" ] || { '
            .'echo "[panelalpha] creating autoload classmap directory missing from the checkout: $d" >&2; '
            .'mkdir -p "$d"; }; done';
    }

    /**
     * The host container's `sh -e` script: dependency steps, then asset steps, in order, since each needs the
     * last's output; a command is load-bearing unless marked `optional: true` with its own `|| true`.
     *
     * @param  bool  $hasComposer  the application ships a composer.json (osTicket does not)
     * @param  ?string  $phpVersion  the minor to pin the resolve to; null leaves the install as declared
     * @param  bool  $lockPhpContradicted  the lock pins a PHP its own packages reject; see {@see self::lockContradictionNote()}
     * @param  bool  $pluginsRestricted  Composer reads the runtime manifest; see {@see runtimeManifest()}
     * @param  bool  $mirrorLock  copy the written lock to composer.lock; see {@see mirrorsRuntimeLock()}
     * @param  ?string  $composerJson  read only for the classmap directories; see {@see classmapDirsStep()}
     */
    public static function script(
        string $install,
        string $build,
        bool $hasComposer = false,
        ?string $phpVersion = null,
        bool $lockPhpContradicted = false,
        bool $pluginsRestricted = false,
        bool $mirrorLock = false,
        ?string $composerJson = null
    ): string {
        $install = trim($install);
        if ($install === '' && $hasComposer) {
            $install = self::DEFAULT_INSTALL;
        }
        $install = self::installCommand($install, $pluginsRestricted);

        $steps = [];
        if ($install !== '') {
            $steps = self::dependencySteps($install, $phpVersion, $lockPhpContradicted, $pluginsRestricted, $mirrorLock, $composerJson);
        }
        if (trim($build) !== '') {
            $steps[] = 'echo "[panelalpha] build: assets" >&2';
            if ($pluginsRestricted) {
                $steps[] = self::restoreProjectAllowPlugins();
            }
            $steps[] = trim($build);
        }

        return implode("\n", $steps);
    }

    /**
     * The allowed plugins are named, so a project missing a plugin's output can see why, and the platform
     * pin is written before Composer reads the platform. {@see platformPin()}
     *
     * @return list<string>
     */
    private static function dependencySteps(
        string $install,
        ?string $phpVersion,
        bool $lockPhpContradicted,
        bool $pluginsRestricted,
        bool $mirrorLock,
        ?string $composerJson
    ): array {
        $steps = [];
        $steps[] = 'echo "[panelalpha] build: dependencies" >&2';
        if ($pluginsRestricted) {
            $steps[] = 'echo "[panelalpha] composer plugins allowed: '
                .implode(', ', self::INSTALLER_PLUGINS).'; any other plugin is installed but not run" >&2';
        }
        if (($pin = self::platformPin($phpVersion)) !== '') {
            $steps[] = $pin;
        }
        if (($dirs = self::classmapDirsStep($composerJson)) !== '') {
            $steps[] = $dirs;
        }
        if ($lockPhpContradicted) {
            $steps[] = self::lockContradictionNote();
            $install .= ' --ignore-platform-req=php';
        }
        $steps[] = $install;
        if ($mirrorLock) {
            $steps[] = '[ -e composer.lock ] || cp '.self::RUNTIME_LOCK_FILE.' composer.lock';
        }

        return $steps;
    }

    /**
     * Restores the project's allow-plugins in the runtime manifest for the asset half (bolt/project needs its
     * plugins there); in place, as the lock is filed under the runtime manifest's name.
     */
    public static function restoreProjectAllowPlugins(): string
    {
        $code = '$m = getenv("'.self::MANIFEST_ENV.'");'
            .' $p = json_decode((string) file_get_contents("composer.json"));'
            .' $r = json_decode((string) file_get_contents($m));'
            .' if (!is_object($r)) { exit(0); }'
            .' if (!isset($r->config) || !is_object($r->config)) { $r->config = new stdClass(); }'
            .' if (is_object($p) && isset($p->config) && is_object($p->config) && property_exists($p->config, "allow-plugins")) {'
            .' $r->config->{"allow-plugins"} = $p->config->{"allow-plugins"}; }'
            .' else { unset($r->config->{"allow-plugins"}); }'
            .' file_put_contents($m, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));';

        return 'php -r '.escapeshellarg($code);
    }

    /**
     * COMPOSER_HOME in /tmp, as the account's home is not mounted; a cache dir that exists either way; no progress
     * bars in a deploy log; no blocking on advisories, which Composer 2.9+ drops, so a lockless resolve finishes.
     *
     * @return array<string, string>
     */
    public static function environment(bool $withCache = true): array
    {
        return [
            'COMPOSER_HOME' => '/tmp/composer',
            'COMPOSER_CACHE_DIR' => $withCache ? PhpBaseImage::COMPOSER_CACHE_DIR : '/tmp/composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_MAX_PARALLEL_HTTP' => '6',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_BLOCKING' => '1',
            'CI' => 'true',
        ];
    }

    /**
     * Where inside the mount the application lives: phpBB's `phpBB/` subtree is mounted at /app, so the build
     * works there too.
     */
    public static function workingDir(string $appRoot): string
    {
        $appRoot = trim($appRoot, '/');

        return $appRoot === '' ? '/app' : '/app/'.$appRoot;
    }
}
