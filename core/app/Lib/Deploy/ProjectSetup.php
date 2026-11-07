<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;

/**
 * First-boot command from the project itself (a Composer/npm-style `setup` script or `bin/setup`), run
 * once behind a marker file so bootstrapping needs no framework recipe. No Laravel dependencies.
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
     * Whether the host build already resolved dependencies (always for PHP, else when build commands exist), so
     * the project's `setup` script must not rerun in the container: it adds require-dev and runs npm with no Node.
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
