<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;

/**
 * First-boot command taken from the project, not from a framework recipe.
 *
 * Composer and npm/yarn/pnpm/bun all use a `setup` script name. Rails-style
 * `bin/setup` is the same idea. We run it once (marker file) so demo data,
 * links, and local bootstrapping happen without hardcoding artisan/rails/django.
 *
 * No Laravel dependencies — unit-testable.
 */
class ProjectSetup
{
    public const MARKER = '.panelalpha-bootstrapped';

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public static function command(
        ?string $composerJson = null,
        ?string $packageJson = null,
        array $files = []
    ): ?string {
        if (self::hasComposerSetup($composerJson)) {
            return 'composer run-script --no-interaction setup';
        }

        $package = self::decodeObject($packageJson);
        $scripts = isset($package['scripts']) && is_array($package['scripts'])
            ? $package['scripts']
            : [];
        if (self::hasNamedScript($scripts, 'setup')) {
            $pm = JsPackageManager::detectPackageManager($files, $package);

            return JsPackageManager::scriptCommand($pm, 'setup');
        }

        if (isset($files['bin/setup']) || isset($files['script/setup'])) {
            $path = isset($files['bin/setup']) ? 'bin/setup' : 'script/setup';

            return 'sh '.$path;
        }

        return null;
    }

    /**
     * Whether the platform has already resolved this project's dependencies,
     * in which case the project's own `setup` script must not run.
     *
     * A `setup` script is a *workstation* script. laravel/laravel's runs
     * `composer install` and `npm install --ignore-scripts`: exactly what a
     * developer who just cloned the repository needs, and exactly what the
     * deploy has already done. Running it again in the runtime container is
     * wrong twice over.
     *
     * It resolves dependencies in the wrong place. The build belongs on the
     * host -- a throwaway container with the account's warm package cache,
     * {@see \App\\System\\Project\Dind\HostCompile} -- not in the
     * container that is meant to be serving by then.
     *
     * And it resolves them *differently*. A bare `composer install` includes
     * require-dev, so phpunit, mockery, faker and pint land in a production
     * account that the host build had just excluded with `--no-dev`; measured
     * on a Laravel skeleton that was 33 extra packages and 10.3s of the 14s
     * between `compose up` and the first answered request. The npm half then
     * fails outright with `npm: not found`, because the shared PHP base ships
     * no Node -- by design, which is why the PHP manifests declare no npm
     * commands either.
     *
     * Two ways a platform says it has already done this. A PHP runtime always
     * has: `runPhpBuild()` runs for every PHP deploy, from the manifest's own
     * commands or from {@see Platform\Runtime\Php\PhpHostBuild::DEFAULT_INSTALL}
     * when it declares none -- which is most of the shipped PHP manifests.
     * Every other runtime says it by declaring build-stage commands.
     *
     * What is left is a platform with no build stage at all, and there the
     * project's own script is the only bootstrap there is, so it still runs.
     */
    public static function isSupersededByPlatform(?string $runtime, bool $hasBuildCommands): bool
    {
        return $runtime === PlatformManifest::RUNTIME_PHP || $hasBuildCommands;
    }

    /**
     * Shell snippet: run $command once, with production-lock env vars relaxed
     * for that process only (APP_ENV / RAILS_ENV / NODE_ENV).
     */
    public static function firstBootShell(?string $command): string
    {
        $command = is_string($command) ? trim($command) : '';
        if ($command === '') {
            return '';
        }

        $marker = self::MARKER;

        return 'if [ ! -f '.$marker.' ]; then '
            .'APP_ENV=local RAILS_ENV=development NODE_ENV=development '
            .$command.' || true; '
            .'touch '.$marker.'; '
            .'fi';
    }

    public static function wrapStartCommand(string $start, ?string $setupCommand): string
    {
        $boot = self::firstBootShell($setupCommand);
        if ($boot === '') {
            return $start;
        }

        return $boot.' && '.$start;
    }

    private static function hasComposerSetup(?string $composerJson): bool
    {
        $composer = self::decodeObject($composerJson);
        $scripts = isset($composer['scripts']) && is_array($composer['scripts'])
            ? $composer['scripts']
            : [];

        if (! array_key_exists('setup', $scripts)) {
            return false;
        }
        $setup = $scripts['setup'];
        if (is_string($setup)) {
            return trim($setup) !== '';
        }
        if (is_array($setup)) {
            return $setup !== [];
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scripts
     */
    private static function hasNamedScript(array $scripts, string $name): bool
    {
        return isset($scripts[$name]) && is_string($scripts[$name]) && trim($scripts[$name]) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
