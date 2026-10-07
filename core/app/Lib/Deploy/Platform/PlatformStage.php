<?php

namespace App\Lib\Deploy\Platform;

/**
 * Where each deploy stage runs:
 *
 * - `precheck` account shell, before the clone. App config commands only —
 *              nothing has been detected yet, since detection reads the source.
 * - `prepare`  account shell, after the clone, before the build.
 * - `build`    image build. Cacheable by BuildKit, so its cost is per source
 *              change, not per boot.
 * - `install`  first successful deploy of an account, once ever.
 * - `upgrade`  redeploys over an account that already holds data; migrations
 *              belong here.
 * - `start`    every container boot, including restarts and crash loops.
 *
 * INSTALL and UPGRADE are mutually exclusive for a given boot, decided from the
 * account's deploy history and passed in as `PA_DEPLOY_PHASE`. START always
 * runs, after whichever of the two applied.
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
     * Env var the generated entrypoint reads to learn which of INSTALL or
     * UPGRADE applies to this boot. Written into the compose file by the
     * engine, which knows whether this account has deployed successfully.
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
     * The phase an account is in, given whether it has ever deployed.
     *
     * A partial deploy still created the schema and counts as deployed: INSTALL
     * again would seed a database that already has rows. So does an account
     * whose later redeploy failed ($deployedBefore).
     */
    public static function phaseFor(?string $deploymentStatus, bool $deployedBefore = false): string
    {
        return $deployedBefore || in_array($deploymentStatus, ['success', 'partial'], true)
            ? self::UPGRADE
            : self::INSTALL;
    }
}
