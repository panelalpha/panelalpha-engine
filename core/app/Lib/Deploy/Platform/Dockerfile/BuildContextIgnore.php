<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\DockerfileBuilder;

/**
 * The `<Dockerfile>.dockerignore` BuildKit reads instead of the context's own (ADR-0001): the project's rules
 * unchanged, then the engine's files, `.git` and an empty `.env`, which miss the cache or leak secrets.
 */
final class BuildContextIgnore
{
    public const SUFFIX = '.dockerignore';

    /** First line of every file the engine writes; how it tells its own from the project's. */
    public const MARKER = '# Written by PanelAlpha on every deploy; edits here are overwritten.';

    /** First line of the `.dockerignore` older engines wrote when a project had none. */
    private const LEGACY_MARKER = '# Written only when a project has none of its own;';

    /** `Dockerfile` -> `Dockerfile.dockerignore`, `./docker/app.Dockerfile` -> `docker/app.Dockerfile.dockerignore`. */
    public static function pathFor(string $dockerfile): string
    {
        return (string) preg_replace('#^(\./)+#', '', $dockerfile).self::SUFFIX;
    }

    /** Whether the engine wrote this file, so it may be rewritten; a project's own one is left alone. */
    public static function isEngineWritten(?string $contents): bool
    {
        return $contents !== null && str_starts_with(ltrim($contents), self::MARKER);
    }

    /** A `.dockerignore` an older engine wrote: the engine's rules stand in for it instead of copying it. */
    public static function isLegacyEngineDockerignore(?string $contents): bool
    {
        return $contents !== null && str_starts_with(ltrim($contents), self::LEGACY_MARKER);
    }

    /**
     * The engine's entries come after the project's rules, because the last match wins.
     *
     * @param  string|null  $projectIgnore  the project's `.dockerignore`, or null when it has none
     * @param  string  $dockerfile  the Dockerfile path relative to the context
     * @param  bool  $excludeGit  false when the build reads git history ({@see GitHistoryUse})
     * @param  bool  $generated  the engine wrote the Dockerfile, so its base rules apply without a `.dockerignore`
     * @param  bool  $excludeEnv  leave `.env` out too ({@see excludesEnv()})
     */
    public static function render(
        ?string $projectIgnore,
        string $dockerfile,
        bool $excludeGit,
        bool $generated,
        bool $excludeEnv = false
    ): string {
        if (self::isLegacyEngineDockerignore($projectIgnore)) {
            $projectIgnore = null;
        }

        $lines = [self::MARKER, ''];
        if ($projectIgnore !== null && trim($projectIgnore) !== '') {
            $lines[] = '# The project\'s .dockerignore, unchanged:';
            $lines[] = rtrim($projectIgnore, "\n");
            $lines[] = '';
        } elseif ($generated) {
            $lines[] = rtrim(self::baseRules(), "\n");
            $lines[] = '';
        }

        $lines[] = '# The engine\'s own files: nothing in the image reads them.';
        foreach (self::engineEntries($dockerfile) as $entry) {
            $lines[] = $entry;
        }
        if ($excludeEnv) {
            $lines[] = '# Empty, created for env_file: a build has nothing to read in it.';
            $lines[] = '.env';
        }
        if ($excludeGit) {
            $lines[] = '# A fresh clone every deploy: its bytes differ even when the commit does not.';
            $lines[] = '.git';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Engine files in the context that no build reads, the 0600 base `.env` copy with its secrets included.
     * The entrypoint and the nginx config are absent: the generated Dockerfile copies them by name.
     *
     * @return list<string>
     */
    public static function engineEntries(string $dockerfile): array
    {
        return array_values(array_unique([
            EngineArtifacts::RUN_COMPOSE,
            EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            EngineArtifacts::APP_CONFIG_COMPOSE,
            EngineArtifacts::ENV_OVERRIDES,
            EngineArtifacts::ENV_DEFAULT,
            EngineArtifacts::RUN_PASSWD,
            EngineArtifacts::RUN_GROUP,
            DockerfileBuilder::FILENAME,
            self::pathFor(DockerfileBuilder::FILENAME),
            self::pathFor($dockerfile),
        ]));
    }

    /**
     * Whether `.env` can be left out: only an empty one (created for `env_file:`) the Dockerfile does not copy
     * by name. A `.env` with values stays, since `COPY . .` is how they reach a Vite or Next build.
     */
    public static function excludesEnv(?string $env, ?string $dockerfileContents): bool
    {
        if ($env === null || trim($env) !== '') {
            return false;
        }

        return preg_match('/^\s*(COPY|ADD)\b[^\n]*\.env\b/im', (string) $dockerfileContents) !== 1;
    }

    /** The rules a generated build gets when the project has no `.dockerignore`. */
    private static function baseRules(): string
    {
        $rules = [];
        foreach (preg_split('/\R/', DockerIgnore::contents()) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && $line !== '.git') {
                $rules[] = $line;
            }
        }

        return implode("\n", $rules);
    }
}
