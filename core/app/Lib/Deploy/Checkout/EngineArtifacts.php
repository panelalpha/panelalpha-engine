<?php

namespace App\Lib\Deploy\Checkout;

use App\Lib\Deploy\Platform\AppConfig\PaemdPage;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\Platform\StageScript;

/**
 * The names the engine reserves inside a checkout for its own files — ADR-0001.
 *
 * A file under one of these names is an Engine Artifact wherever it appears in
 * a Deploy-managed checkout. Files the engine only writes when missing (`.env`,
 * `.dockerignore`, a placeholder page) are not reserved: the client may own
 * them, so whether they are artifacts is decided per checkout.
 */
final class EngineArtifacts
{
    /** The compose file the engine runs. */
    public const RUN_COMPOSE = 'docker-compose.panelalpha.yml';

    /** An app config's compose file in `override` mode, layered over the run file. */
    public const RUN_COMPOSE_OVERRIDE = 'docker-compose.panelalpha.override.yml';

    /** An app config's compose file in `replace` mode, read ahead of the repository's. */
    public const APP_CONFIG_COMPOSE = 'docker-compose.panelalpha.app-config.yml';

    /** `env_vars` when the repository tracks `.env`. */
    public const ENV_OVERRIDES = '.env.panelalpha';

    /** The base `.env` the project shipped, before `env_vars` were merged. */
    public const ENV_DEFAULT = '.env.default';

    public const RAILS_HOST_INITIALIZER = 'config/initializers/zz_panelalpha_hosts.rb';

    /**
     * Suffix the previous engine moved a shadowing compose file to, so
     * `docker-compose.yml` was free for its own generated output. No longer
     * written; kept only for the migration command to find and undo.
     */
    public const LEGACY_STASH_SUFFIX = '.panelalpha-local';

    /**
     * Anchored gitignore patterns for every reserved name, including ones a
     * later engine starts writing, so they are excluded the moment they appear.
     *
     * @return list<string>
     */
    public static function reservedPatterns(): array
    {
        return array_map(CheckoutExclude::anchoredPath(...), [
            DockerfileBuilder::FILENAME,
            NginxConfig::FILENAME,
            StandaloneNodeServe::FILENAME,
            StageScript::FILENAME,
            PaemdPage::SETUP_SCRIPT,
            PaemdPage::APP_SCRIPT,
            PhpHostBuild::RUNTIME_MANIFEST_FILE,
            PhpHostBuild::RUNTIME_LOCK_FILE,
            self::ENV_DEFAULT,
            self::ENV_OVERRIDES,
            self::RUN_COMPOSE,
            self::RUN_COMPOSE_OVERRIDE,
            self::APP_CONFIG_COMPOSE,
            self::RAILS_HOST_INITIALIZER,
        ]);
    }
}
