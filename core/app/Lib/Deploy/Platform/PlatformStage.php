<?php

namespace App\Lib\Deploy\Platform;

/**
 * Where each stage runs: precheck (app config only, as detection reads the source) and prepare in the account shell around the clone;
 * build in the image, cached per source change; per boot, install (once ever) or upgrade (migrations, over data), then always start.
 */
final class PlatformStage
{
    /** Account shell, before the clone. App-config-only — see the class note. */
    public const PRECHECK = 'precheck';

    /** Account shell, after the clone, before the build. */
    public const PREPARE = 'prepare';

    public const BUILD = 'build';

    public const INSTALL = 'install';

    public const UPGRADE = 'upgrade';

    public const START = 'start';

    /**
     * Env var telling the generated entrypoint whether INSTALL or UPGRADE applies to this boot, written into the compose file by
     * the engine, which knows whether this account has deployed successfully.
     */
    public const PHASE_ENV = 'PA_DEPLOY_PHASE';

    /** Execution order within one boot. Only these reach the entrypoint. */
    public const RUNTIME_ORDER = [self::INSTALL, self::UPGRADE, self::START];

    /**
     * The host stages, in deploy order.
     *
     * @var list<string>
     */
    public const HOST_ORDER = [self::PRECHECK, self::PREPARE];

    /** @var list<string> */
    public const ALL = [
        self::PRECHECK,
        self::PREPARE,
        self::BUILD,
        self::INSTALL,
        self::UPGRADE,
        self::START,
    ];

    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::ALL, true);
    }

    /** Does this stage run in the account's shell rather than a container? */
    public static function isHostStage(string $stage): bool
    {
        return in_array($stage, self::HOST_ORDER, true);
    }

    /**
     * @return list<string>
     */
    public static function hostStages(): array
    {
        return self::HOST_ORDER;
    }

    /**
     * @return list<string>
     */
    public static function runtimeStages(): array
    {
        return self::RUNTIME_ORDER;
    }

    /**
     * The phase an account is in, given whether it has ever deployed. A partial deploy created the schema and counts, as INSTALL
     * again would seed a database that has rows; so does an account whose later redeploy failed ($deployedBefore).
     */
    public static function phaseFor(?string $deploymentStatus, bool $deployedBefore = false): string
    {
        return $deployedBefore || in_array($deploymentStatus, ['success', 'partial'], true)
            ? self::UPGRADE
            : self::INSTALL;
    }
}
