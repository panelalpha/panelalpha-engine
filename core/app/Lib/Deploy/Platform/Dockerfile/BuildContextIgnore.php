<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\DockerfileBuilder;

/**
 * The ignore file BuildKit reads for one Dockerfile: `<Dockerfile>.dockerignore`
 * next to it, used instead of the context's `.dockerignore`.
 *
 * Written so the engine can keep its own files and `.git` out of the build
 * context without editing a `.dockerignore` the project owns (ADR-0001). The
 * project's rules are copied in first, unchanged, so they still apply.
 *
 * What it keeps out, and why:
 * - the compose files the engine writes: `PA_DEPLOY_PHASE` flips from
 *   `install` to `upgrade` on the second deploy, so `COPY . .` missed;
 * - `.git`: every deploy is a fresh clone, and two clones of one commit have
 *   different `.git` bytes, so `COPY . .` missed on every deploy;
 * - `.env.panelalpha`: the account's env_vars, which must not be baked into
 *   an image;
 * - an empty `.env` the engine created for `env_file:`, when the Dockerfile
 *   does not copy it by name: Apache Guacamole's RAT license check failed on
 *   it and on the compose file (engine#162). See {@see excludesEnv()}.
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
        return (string) preg_replace('#^(\./)+#', '', $dockerfile) . self::SUFFIX;
    }

    /**
     * Whether this file is one the engine wrote, so it may be rewritten.
     * A project's own `<Dockerfile>.dockerignore` is left alone.
     */
    public static function isEngineWritten(?string $contents): bool
    {
        return $contents !== null && str_starts_with(ltrim($contents), self::MARKER);
    }

    /**
     * A `.dockerignore` an older engine wrote rather than the project: the
     * engine's rules stand in for it instead of it being copied as the project's.
     */
    public static function isLegacyEngineDockerignore(?string $contents): bool
    {
        return $contents !== null && str_starts_with(ltrim($contents), self::LEGACY_MARKER);
    }

    /**
     * @param string|null $projectIgnore the project's `.dockerignore`, or null when it has none
     * @param string      $dockerfile    the Dockerfile path relative to the context
     * @param bool        $excludeGit    false when the build reads git history ({@see GitHistoryUse})
     * @param bool        $generated     the engine wrote the Dockerfile, so its base rules apply
     *                                   when the project has no `.dockerignore`
     * @param bool        $excludeEnv    leave `.env` out too ({@see excludesEnv()})
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

        // Last match wins, so these come after the project's rules.
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

        return implode("\n", $lines) . "\n";
    }

    /**
     * Engine files that sit in the context and no build reads.
     * `panelalpha-entrypoint.sh` and the nginx config are not here: the
     * generated Dockerfile copies them by name.
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
            // The engine's 0600 copy of the base .env, generated secrets included.
            EngineArtifacts::ENV_DEFAULT,
            EngineArtifacts::RUN_PASSWD,
            EngineArtifacts::RUN_GROUP,
            DockerfileBuilder::FILENAME,
            self::pathFor(DockerfileBuilder::FILENAME),
            self::pathFor($dockerfile),
        ]));
    }

    /**
     * Whether `.env` can be left out: only when it holds nothing a build could
     * read (the engine creates an empty one for `env_file:`) and the
     * Dockerfile does not copy it by name. A `.env` with values stays, since
     * `COPY . .` and a Vite or Next build reading it is how those values
     * reach the build.
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
