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
     * `--no-scripts` always; `--no-plugins` unless the lock pins only plugins
     * the engine has decided are installers (see {@see INSTALLER_PLUGINS}).
     *
     * Both execute arbitrary PHP out of the customer's repository, and this
     * runs on the host daemon. The manifest's own `post-autoload-dump` step
     * runs afterwards with plugins allowed, by which point the tree is
     * resolved — a plugin like Magento's dies on a missing vendor_path.php
     * before that.
     *
     * The exception exists because for a class of frameworks the plugin *is*
     * the installer: `composer/installers` reads `installer-paths` and moves
     * each package into the directory it names, so nothing but `vendor/` exists
     * until it has run. With plugins disabled a Drupal install reported 46
     * packages installed, produced no web root, and the deploy ended on "there
     * is no index.php anywhere in ~/project".
     */
    public const SAFE_INSTALL_FLAGS = '--no-dev --no-interaction --no-scripts --no-plugins';

    /**
     * Composer plugins that scaffold a project instead of building one, and may
     * run during the install when the project's own lock pins them.
     *
     * A plugin runs only if `composer.lock` lists it under `packages` with
     * `"type": "composer-plugin"`, so a project cannot acquire one without
     * committing it — which is why this list is checked against the lock rather
     * than trusted from a manifest.
     *
     * composer/installers, drupal/core-composer-scaffold,
     * drupal/core-recipe-unpack, drupal/core-project-message and
     * symfony/runtime all write files a project loads instead of running a
     * build. symfony/runtime is here mainly because `drupal/recommended-project`
     * pins all five, and the all-or-nothing rule below refuses a partial set. A
     * plugin that runs an application's build, Magento's for instance, belongs
     * to the manifest's `post-autoload-dump` step instead.
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
     * The plugin packages a lock pins, or [] when it has no lock to read.
     *
     * Read from `packages` only, never `packages-dev`: `--no-dev` means a
     * dev-time plugin is not installed, so allowing one would be permission to
     * run code the install then skips.
     *
     * @return list<string> lowercased package names of type composer-plugin
     */
    public static function lockedPlugins(?string $composerLock): array
    {
        $decoded = json_decode((string) $composerLock, true);
        if (!is_array($decoded) || !isset($decoded['packages']) || !is_array($decoded['packages'])) {
            return [];
        }

        $plugins = [];
        foreach ($decoded['packages'] as $package) {
            if (!is_array($package)) {
                continue;
            }
            if (($package['type'] ?? null) !== 'composer-plugin') {
                continue;
            }
            $name = $package['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $plugins[] = strtolower($name);
            }
        }

        return $plugins;
    }

    /**
     * Whether the install may run plugins: the lock pins at least one, and
     * every one it pins is an installer, not an application's build.
     *
     * All-or-nothing: Composer cannot allow one plugin and refuse another, so a
     * project pinning both `composer/installers` and a build plugin cannot be
     * given the first alone. Refusing is the safe direction, and the failure
     * names the plugin.
     */
    public static function mayRunPlugins(?string $composerLock): bool
    {
        $locked = self::lockedPlugins($composerLock);
        if ($locked === []) {
            return false;
        }

        foreach ($locked as $plugin) {
            if (!in_array($plugin, self::INSTALLER_PLUGINS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The dependency install command, with `--no-plugins` dropped when the
     * lock's plugins are all installers. {@see mayRunPlugins()}
     *
     * A declared install passes through untouched except that one flag: a
     * manifest that spelled its own `composer install` still gets it, and only
     * the `--no-plugins` it carries is reconsidered.
     */
    public static function installCommand(string $install, ?string $composerLock): string
    {
        if ($install === '' || !self::mayRunPlugins($composerLock)) {
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
     * The copy of the manifest Composer resolves from: when require-dev is
     * being dropped, or when a locked project's platform pin would otherwise
     * land in composer.json. Beside composer.json and inside the mount, so it
     * is the same file to the build container as to the account.
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
     * The manifest Composer should resolve from, and null when the project's
     * own composer.json will do.
     *
     * Composer still resolves require-dev under `--no-dev` — `--no-dev` says
     * which packages are *installed*, not which the resolver may reject the
     * graph over — and since Composer 2.7 versions with a security advisory are
     * removed from the pool by default. So a dev-only pin can block a
     * production install (Islandora's require-dev pins phpunit 6). Composer has
     * no per-scope advisory policy, so the resolver is handed a manifest with
     * `require-dev` removed and every runtime advisory left in place.
     *
     * Null whenever the file is unreadable or has no require-dev to drop.
     *
     * A project shipping a composer.lock is different: `install` reads the
     * lock and resolves nothing, so the advisory rule this manifest dodges
     * cannot fire there, and ordinarily nothing needs to move. Composer finds
     * a lock by the name of the manifest handed to it, though, so once
     * something *does* need writing — {@see platformPin()} — pointing
     * `COMPOSER` at a runtime manifest with no lock beside it would make
     * Composer look for `.pa-runtime-composer.lock`, find none, and resolve
     * the whole graph against the remote repositories instead of installing
     * the committed one — reaching api.github.com and dying on the
     * unauthenticated 60-requests-per-hour budget every deploy on the host's
     * egress IP shares. So a lock with nothing to pin still returns null and
     * installs straight from composer.json/.lock; a lock with a pin returns
     * composer.json byte for byte, on the understanding that the caller
     * copies composer.lock beside it under the matching engine name before
     * Composer ever sees `COMPOSER` pointed elsewhere.
     */
    public static function runtimeManifest(string $composerJson, ?string $composerLock = null, ?string $phpVersion = null): ?string
    {
        if ($composerLock !== null && trim($composerLock) !== '') {
            return self::platformPin($phpVersion) !== '' ? $composerJson : null;
        }

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
        if (!is_object($decoded) || !isset($decoded->{'require-dev'})) {
            return null;
        }
        if (!isset($decoded->require) || !is_object($decoded->require)) {
            // Composer's own validator rejects a root with no `require`,
            // and a manifest without one would resolve an empty graph.
            return null;
        }

        unset($decoded->{'require-dev'});

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || $encoded === $composerJson) {
            return null;
        }

        return $encoded;
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
     * @param ?string $composerLock the project's lock, read only to decide
     *        whether the plugins it pins may run; see {@see mayRunPlugins()}.
     */
    public static function script(
        string $install,
        string $build,
        bool $hasComposer = false,
        ?string $phpVersion = null,
        bool $lockPhpContradicted = false,
        ?string $composerLock = null
    ): string {
        $install = trim($install);
        if ($install === '' && $hasComposer) {
            $install = self::DEFAULT_INSTALL;
        }
        // Dropped here, not left out of DEFAULT_INSTALL: a manifest that
        // declared its own `composer install` carries the flag too.
        // {@see mayRunPlugins()}
        $install = self::installCommand($install, $composerLock);

        $steps = [];
        if ($install !== '') {
            $steps[] = 'echo "[panelalpha] build: dependencies" >&2';
            // Ahead of the install: the pin is a `composer config` write that
            // must happen before Composer reads the platform. {@see platformPin()}
            if (($pin = self::platformPin($phpVersion)) !== '') {
                $steps[] = $pin;
            }
            if ($lockPhpContradicted) {
                $steps[] = self::lockContradictionNote();
                $install .= " --ignore-platform-req=php";
            }
            $steps[] = $install;
        }
        if (trim($build) !== '') {
            $steps[] = 'echo "[panelalpha] build: assets" >&2';
            $steps[] = trim($build);
        }

        return implode("\n", $steps);
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
