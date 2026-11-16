<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\ManifestException;

/**
 * A `.panelalpha/` directory, the app config as files: `hooks/` run between stages, `overrides/` replace a file the engine or
 * the project would supply, `files/` are copied in. The engine's own `resources/sources/` directories have the same shape.
 */
final class AppConfigDirectory
{
    public const DIRNAME = '.panelalpha';

    public const CONFIG = 'panelalpha.yaml';

    public const PRECHECK = 'hooks/precheck.sh';

    public const PREPARE = 'hooks/prepare.sh';

    public const ENTRYPOINT = 'overrides/entrypoint.sh';

    public const APP_SCRIPT = 'overrides/app.sh';

    public const COMPOSE = 'overrides/docker-compose.yml';

    public const COMPOSE_OVERRIDE = 'overrides/docker-compose.override.yml';

    public const FILES = 'files';

    /**
     * The app config this directory holds, or null when it holds nothing the engine
     * reads.
     *
     * @param  bool  $requireConfig  for the engine's own tree, where a directory without a
     *                               `panelalpha.yaml` is a half-written recipe. A repository's own directory is
     *                               taken as it comes.
     * @param  ?string  $subject  how errors name the `panelalpha.yaml`; the directory's own
     *                            path when the caller has no better label
     *
     * @throws ManifestException
     */
    public static function read(
        AppConfigSource $source,
        string $dir,
        bool $requireConfig = false,
        ?string $subject = null
    ): ?AppConfig {
        $dir = rtrim($dir, '/');
        if (! $source->isDirectory($dir)) {
            return null;
        }
        if ($requireConfig && self::config($source, $dir) === null) {
            throw new ManifestException("{$dir}: ".self::CONFIG.' is required');
        }

        $replace = self::contents($source, $dir, self::COMPOSE);
        $override = self::contents($source, $dir, self::COMPOSE_OVERRIDE);
        self::assertOneComposeFile($dir, $replace, $override);

        return AppConfig::fromDirectory([
            'config' => self::contents($source, $dir, self::CONFIG),
            'precheck' => self::contents($source, $dir, self::PRECHECK),
            'prepare' => self::contents($source, $dir, self::PREPARE),
            'entrypoint' => self::contents($source, $dir, self::ENTRYPOINT),
            'app' => self::contents($source, $dir, self::APP_SCRIPT),
            'compose' => $replace ?? $override,
            'compose_mode' => $replace !== null
                ? AppConfig::COMPOSE_REPLACE
                : AppConfig::COMPOSE_OVERRIDE,
            'files' => self::snippets($source, $dir),
        ], $subject ?? $dir.'/'.self::CONFIG);
    }

    /** @throws ManifestException when the directory both replaces the project's compose file and layers over it */
    private static function assertOneComposeFile(string $dir, ?string $replace, ?string $override): void
    {
        if ($replace !== null && $override !== null) {
            throw new ManifestException(
                "{$dir}: ships both ".self::COMPOSE.' and '.self::COMPOSE_OVERRIDE
                .' — one replaces the project\'s compose file, the other layers over it'
            );
        }
    }

    /** Just the config, for a caller that only wants what the YAML declares. */
    public static function config(AppConfigSource $source, string $dir): ?string
    {
        return self::contents($source, rtrim($dir, '/'), self::CONFIG);
    }

    /** @return list<array{path: string, contents: string}> */
    private static function snippets(AppConfigSource $source, string $dir): array
    {
        $root = $dir.'/'.self::FILES;
        $snippets = [];
        foreach ($source->listFiles($root) as $relative) {
            $contents = $source->read($root.'/'.$relative);
            if (is_string($contents)) {
                $snippets[] = ['path' => $relative, 'contents' => $contents];
            }
        }

        return $snippets;
    }

    private static function contents(AppConfigSource $source, string $dir, string $name): ?string
    {
        $contents = $source->read($dir.'/'.$name);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }
}
