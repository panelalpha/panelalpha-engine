<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\ProjectCache;

/**
 * What a PHP project's build looks like once it happens on the host.
 *
 * No per-project image exists, so `composer install` runs in a throwaway
 * container on the host daemon from the same shared base the application is
 * served from, with `~/project` bind-mounted. The `vendor/` it writes lands in
 * the document root, so the running container sees it without a restart.
 *
 * From the runtime image the PHP minor and extension set are real: a package
 * needing `ext-soap` fails at build time, not at the first request.
 *
 * No Laravel dependencies — unit-testable.
 */
final class PhpHostBuild
{


    /**
     * `--no-scripts` always. `--no-plugins` is the fail-safe default, dropped
     * only once the build resolves from the engine's runtime manifest, whose
     * `allow-plugins` names {@see INSTALLER_PLUGINS} and refuses the rest
     * ({@see allowPlugins()}).
     *
     * Both execute arbitrary PHP out of the customer's repository, and this
     * runs on the host daemon. The exception exists because for a class of
     * frameworks the plugin *is* the installer: `composer/installers` reads
     * `installer-paths` and moves each package into the directory it names,
     * so nothing but `vendor/` exists until it has run. With plugins disabled
     * a Drupal install reported 46 packages installed, produced no web root,
     * and the deploy ended on "there is no index.php anywhere in ~/project".
     */
    public const SAFE_INSTALL_FLAGS = '--no-dev --no-interaction --no-scripts --no-plugins';

    /**
     * Composer plugins that scaffold a project instead of building one, and
     * the only ones the host install lets Composer run.
     *
     * The install only: the manifest's own `post-autoload-dump` step runs
     * afterwards against the project's composer.json, so it loads whatever
     * plugins the project's allow-plugins trusts, as it always has -- by which
     * point the tree is resolved (a plugin like Magento's dies on a missing
     * vendor_path.php before that).
     *
     * composer/installers, drupal/core-composer-scaffold,
     * drupal/core-recipe-unpack and drupal/core-project-message write files a
     * project loads instead of running a build. symfony/runtime writes
     * `vendor/autoload_runtime.php`, which every Symfony 5.3+ front controller
     * requires on its first line. A plugin that runs an application's build,
     * Magento's for instance, belongs to the manifest's own steps instead.
     *
     * Matched by package name, like everything in `allow-plugins`.
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
     * The `config.allow-plugins` the runtime manifest carries: each installer
     * allowed by name, then `"*": false` for everything else.
     *
     * Composer takes the first rule that matches, so the catch-all is last.
     * `false` rather than absent: a plugin allow-plugins does not mention
     * throws PluginBlockedException under --no-interaction, while an explicit
     * false skips it silently. The project's own allow-plugins is replaced,
     * not merged, so `"*": true` there cannot re-enable anything.
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
     * The dependency install command, with `--no-plugins` dropped when the
     * build resolves from the runtime manifest and so under
     * {@see allowPlugins()}.
     *
     * A declared install passes through untouched except that one flag: a
     * manifest that spelled its own `composer install` still gets it, and only
     * the `--no-plugins` it carries is reconsidered.
     */
    public static function installCommand(string $install, bool $pluginsRestricted): string
    {
        if ($install === '' || !$pluginsRestricted) {
            return $install;
        }

        return trim((string) preg_replace('/\s*--no-plugins\b/', '', $install));
    }

    /**
     * What a PHP project's dependency step is when its manifest does not say.
     *
     * Most PHP manifests declare no build commands (chamilo, phpbb, magento,
     * suitecrm and the rest), and taking the manifest as the only source
     * deployed them with no `vendor/` at all: the host build had nothing to
     * run, the container started, and the application 500'd on its first
     * request looking for an autoloader. The default lives here so writing a
     * new PHP manifest cannot reintroduce it.
     *
     * `--optimize-autoloader` because nothing dumps the autoloader afterwards
     * unless the manifest declares a step.
     */
    public const DEFAULT_INSTALL = 'composer install ' . self::SAFE_INSTALL_FLAGS . ' --optimize-autoloader';

    /**
     * The copy of the manifest Composer resolves from: the engine's
     * allow-plugins, require-dev dropped when there is no lock, and the
     * platform pin, none of which belongs in the customer's composer.json.
     * Beside composer.json and inside the mount, so it is the same file to the
     * build container as to the account.
     *
     * @see runtimeManifest()
     */
    public const RUNTIME_MANIFEST_FILE = '.pa-runtime-composer.json';

    /** Reserved for a copy of the client's `composer.lock` beside {@see RUNTIME_MANIFEST_FILE} (ADR-0001). */
    public const RUNTIME_LOCK_FILE = '.pa-runtime-composer.lock';

    /**
     * The environment variable that points Composer at a manifest other than
     * composer.json. Set as a `-e` on the container's argv, not through the
     * shell, so it reaches every Composer invocation the step makes (`composer
     * config` included) without quoting to get wrong.
     */
    public const MANIFEST_ENV = 'COMPOSER';

    /**
     * `composer config platform.php <minor>.99`, or '' when there is no minor
     * worth pinning.
     *
     * The one spelling, shared with the Composer-image pass
     * ({@see \App\Lib\Deploy\Dind\DindHostBuilder::composerInstallArgv()}) so
     * the two cannot drift. They had: only that pass pinned, so a project's own
     * `config.platform` won against the image the engine built — YOURLS
     * declares `"platform": {"php": "8.1.0"}` beside `require.php ^8.4`, and
     * the deploy built 8.4 then failed the resolve against 8.1.
     *
     * `.99` because the engine knows the minor, not the patch the base image
     * ships, and `.0` would reject a package needing a later patch.
     *
     * No `--file`: `composer config` inherits COMPOSER ({@see MANIFEST_ENV}),
     * so it writes into whichever manifest the resolve is about to read, and
     * the customer's composer.json is left alone when there is nothing to drop.
     * No version is interpolated until it is shaped like a minor — the value
     * arrives from a project's own composer.json and ends up in a shell command.
     */
    public static function platformPin(?string $phpVersion): string
    {
        if ($phpVersion === null || preg_match('/^\d+\.\d+$/', $phpVersion) !== 1) {
            return '';
        }

        return 'composer config --no-plugins platform.php ' . $phpVersion . '.99';
    }

    /**
     * What the deploy log says when the lock contradicts itself.
     *
     * Without it the only trace is a warning Composer printed above, and a
     * reviewer asking why php was ignored. The sentence is about the project,
     * not the engine: the lock is not wrong, it is out of date.
     */
    public static function lockContradictionNote(): string
    {
        return 'echo "[panelalpha] this project\'s composer.lock pins a PHP its own '
            . 'packages reject, so the locked set is installed as-is; php is the one '
            . 'requirement not enforced for it" >&2';
    }

    /**
     * The manifest Composer should resolve from, or null when composer.json
     * is not a JSON object, in which case the build keeps `--no-plugins`.
     *
     * Always the project's manifest with `config.allow-plugins` replaced by
     * {@see allowPlugins()}: Composer 2.2+ enforces it per plugin, so the
     * installers run and every other plugin is installed but never loaded,
     * whatever the project's own allow-plugins says and with or without a
     * lock. The old gate was keyed on composer.lock and all-or-nothing, so a
     * lockless Symfony project (bolt/project, pimcore/skeleton, thelia) was
     * built `--no-plugins` and never got `vendor/autoload_runtime.php`.
     *
     * Without a lock, `require-dev` is dropped too: Composer still resolves it
     * under `--no-dev`, and since Composer 2.7 versions with a security
     * advisory are removed from the pool, so a dev-only pin can block a
     * production install (Islandora's require-dev pins phpunit 6).
     *
     * With a lock, require-dev stays, so the lock's content-hash still
     * matches (allow-plugins is not part of it). Composer finds a lock by the
     * name of the manifest handed to it, so the caller copies composer.lock
     * beside this file as {@see RUNTIME_LOCK_FILE} before Composer sees
     * `COMPOSER`; otherwise it would resolve the whole graph remotely and meet
     * the unauthenticated api.github.com budget every deploy on the host
     * shares. {@see platformPin()} then writes into this file, never into
     * composer.json.
     */
    public static function runtimeManifest(string $composerJson, ?string $composerLock = null): ?string
    {
        // Decoded as objects, not associative arrays, so what is written
        // back is the same *shape* Composer validated. As arrays an empty
        // `require` re-encodes as `[]`, and Composer's schema requires an
        // object there, so it refuses the whole file:
        //
        //     require : Array value found, but an object is required
        //
        // Objects round-trip `{}` as `{}` and leave real lists (`classmap`,
        // `authors`) as lists, which JSON_FORCE_OBJECT would not.
        $decoded = json_decode($composerJson);
        if (!is_object($decoded)) {
            return null;
        }

        $locked = $composerLock !== null && trim($composerLock) !== '';
        // A root with no `require` object keeps its require-dev: dropping it
        // would leave an empty graph.
        if (!$locked && isset($decoded->{'require-dev'}) && isset($decoded->require) && is_object($decoded->require)) {
            unset($decoded->{'require-dev'});
        }

        if (!isset($decoded->config) || !is_object($decoded->config)) {
            $decoded->config = new \stdClass();
        }
        $decoded->config->{'allow-plugins'} = (object) self::allowPlugins();

        $encoded = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $encoded === false ? null : $encoded;
    }

    /**
     * Whether a lockless build should leave its lock as composer.lock too.
     *
     * Before #168 a lockless project with nothing to drop resolved from
     * composer.json itself, so Composer wrote composer.lock, and container
     * steps rely on it: contao's `composer install` installs from the lock the
     * host wrote instead of resolving again. Now that such a project resolves
     * from the runtime manifest, its lock lands under
     * {@see RUNTIME_LOCK_FILE}; this says when to copy it back. Not when
     * require-dev was dropped: that lock never was composer.lock.
     */
    public static function mirrorsRuntimeLock(string $composerJson, ?string $composerLock): bool
    {
        if ($composerLock !== null && trim($composerLock) !== '') {
            return false;
        }
        $decoded = json_decode($composerJson);

        return is_object($decoded)
            && !(isset($decoded->{'require-dev'}) && isset($decoded->require) && is_object($decoded->require));
    }

    /**
     * The host's Composer cache for this project -- one named cache beside the
     * JS ones, under the project's own directory. {@see ProjectCache}
     */
    public static function cacheDirFor(string $username): string
    {
        return ProjectCache::subdirFor($username, ProjectCache::COMPOSER);
    }

    /**
     * Where the *running container* caches Composer.
     *
     * Not {@see cacheDirFor()}, and the difference is which daemon reads the
     * path. The application's compose file runs on the account's *nested*
     * daemon, which resolves bind-mount sources inside the account container
     * where `/var/cache/panelalpha` is not mounted — the inner daemon then
     * created that directory as root and Composer, running as the account, went
     * uncached behind a mount that looked correct in the compose file.
     *
     * The account's home is the one path both daemons agree on, and it survives
     * the `git clone` that wipes `~/project`.
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

        return $home . '/.cache/composer';
    }

    /**
     * Create the root `autoload.classmap` directories a fresh checkout lacks,
     * or '' when there are none to create.
     *
     * Composer's autoload dump throws on a classmap path that does not exist,
     * optimized or not. ILIAS lists `public/Customizing/global/plugins`, which
     * is gitignored and made by its own `pre-install-cmd` -- a script
     * `--no-scripts` skips. Only plain relative directory names are taken: a
     * path with an extension is a file, and the vendor dir is Composer's own.
     */
    public static function classmapDirsStep(?string $composerJson): string
    {
        $decoded = json_decode((string) $composerJson, true);
        $classmap = is_array($decoded) ? ($decoded['autoload']['classmap'] ?? null) : null;
        if (!is_array($classmap)) {
            return '';
        }
        $vendor = $decoded['config']['vendor-dir'] ?? 'vendor';
        $vendor = is_string($vendor) ? trim((string) preg_replace('#^(\./)+#', '', $vendor), '/') : 'vendor';

        $dirs = [];
        foreach ($classmap as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim((string) preg_replace('#^(\./)+#', '', trim($path)), '/');
            if ($path === ''
                || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $path) !== 1
                || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
                || str_contains(basename($path), '.')
                || $path === $vendor
                || str_starts_with($path, $vendor . '/')
            ) {
                continue;
            }
            $dirs[] = escapeshellarg($path);
        }
        if ($dirs === []) {
            return '';
        }

        return 'for d in ' . implode(' ', array_unique($dirs)) . '; do [ -e "$d" ] || { '
            . 'echo "[panelalpha] creating autoload classmap directory missing from the checkout: $d" >&2; '
            . 'mkdir -p "$d"; }; done';
    }

    /**
     * The script the host container runs: the manifest's dependency-role build
     * commands, then its asset-role ones.
     *
     * Ordered, not merged: `composer install` must finish before
     * `post-autoload-dump` has a vendor tree, and Adminer's `compile.php` must
     * run after both or there is no index.php to serve. Run under `sh -e`, so a
     * command is load-bearing unless it arrived marked `optional: true` and
     * carrying its own `|| true`.
     *
     * @param bool $hasComposer the application ships a composer.json. osTicket
     *        vendors its dependencies and ships none, and `composer install`
     *        against a directory with no composer.json fails the deploy for an
     *        application that never needed Composer at all.
     * @param ?string $phpVersion the minor the app will run on, so the resolve
     *        is pinned to it and the project's own `config.platform` cannot
     *        override the image the engine chose. Null leaves the install
     *        exactly as the manifest declared it.
     * @param bool $lockPhpContradicted the lock pins a PHP its own packages
     *        cannot run on, so the deploy must not fail on that requirement.
     *        Reported then ignored; see {@see self::lockContradictionNote()}.
     * @param bool $pluginsRestricted Composer reads the runtime manifest, so
     *        its allow-plugins holds and `--no-plugins` may go; see
     *        {@see runtimeManifest()}. False keeps the flag.
     * @param bool $mirrorLock copy the lock the install wrote to composer.lock
     *        when there is none; see {@see mirrorsRuntimeLock()}.
     * @param ?string $composerJson the project's manifest, read only for the
     *        classmap directories to create; see {@see classmapDirsStep()}.
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
        // Dropped here, not left out of DEFAULT_INSTALL: a manifest that
        // declared its own `composer install` carries the flag too.
        $install = self::installCommand($install, $pluginsRestricted);

        $steps = [];
        if ($install !== '') {
            $steps[] = 'echo "[panelalpha] build: dependencies" >&2';
            if ($pluginsRestricted) {
                // So a project missing a plugin's output can see why.
                $steps[] = 'echo "[panelalpha] composer plugins allowed: '
                    . implode(', ', self::INSTALLER_PLUGINS) . '; any other plugin is installed but not run" >&2';
            }
            // Ahead of the install: the pin is a `composer config` write that
            // must happen before Composer reads the platform. {@see platformPin()}
            if (($pin = self::platformPin($phpVersion)) !== '') {
                $steps[] = $pin;
            }
            if (($dirs = self::classmapDirsStep($composerJson)) !== '') {
                $steps[] = $dirs;
            }
            if ($lockPhpContradicted) {
                $steps[] = self::lockContradictionNote();
                $install .= " --ignore-platform-req=php";
            }
            $steps[] = $install;
            if ($mirrorLock) {
                $steps[] = '[ -e composer.lock ] || cp ' . self::RUNTIME_LOCK_FILE . ' composer.lock';
            }
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
     * Put the project's own allow-plugins back into the runtime manifest for
     * the asset half, which is how it read before #168.
     *
     * The allowlist is for the install. php.yaml's `run-script
     * post-autoload-dump` loads whatever the project trusts, as it always
     * has: bolt/project boots only because drupol/composer-packages generates
     * its classes there. Restricting that step too is a separate decision.
     * Rewritten in place rather than unsetting COMPOSER, because the lock the
     * install wrote is filed under the runtime manifest's name and
     * package-versions-deprecated refuses to run without one.
     */
    public static function restoreProjectAllowPlugins(): string
    {
        $code = '$m = getenv("' . self::MANIFEST_ENV . '");'
            . ' $p = json_decode((string) file_get_contents("composer.json"));'
            . ' $r = json_decode((string) file_get_contents($m));'
            . ' if (!is_object($r)) { exit(0); }'
            . ' if (!isset($r->config) || !is_object($r->config)) { $r->config = new stdClass(); }'
            . ' if (is_object($p) && isset($p->config) && is_object($p->config) && property_exists($p->config, "allow-plugins")) {'
            . ' $r->config->{"allow-plugins"} = $p->config->{"allow-plugins"}; }'
            . ' else { unset($r->config->{"allow-plugins"}); }'
            . ' file_put_contents($m, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));';

        return 'php -r ' . escapeshellarg($code);
    }

    /**
     * The environment a host composer run needs beyond the image's own.
     *
     * COMPOSER_HOME is /tmp, not the account's home: the container runs as the
     * account's uid but its home is not mounted, and composer that cannot write
     * a home directory stops before it starts.
     *
     * @return array<string, string>
     */
    public static function environment(bool $withCache = true): array
    {
        return [
            'COMPOSER_HOME' => '/tmp/composer',
            // Somewhere writable either way: pointed at a cache mount that is
            // not there, composer falls over instead of resolving without one.
            'COMPOSER_CACHE_DIR' => $withCache ? PhpBaseImage::COMPOSER_CACHE_DIR : '/tmp/composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_MAX_PARALLEL_HTTP' => '6',
            // Nothing here is a terminal, and a progress bar redrawn into a
            // deploy log is thousands of lines of escape codes.
            'COMPOSER_NO_INTERACTION' => '1',
            'CI' => 'true',
        ];
    }

    /**
     * Where inside the mount the application actually lives.
     *
     * phpBB is the repository root plus a `phpBB/` directory that is the
     * application; the compose file mounts that subtree at /app, so the build
     * has to work on the same place.
     */
    public static function workingDir(string $appRoot): string
    {
        $appRoot = trim($appRoot, '/');

        return $appRoot === '' ? '/app' : '/app/' . $appRoot;
    }
}
