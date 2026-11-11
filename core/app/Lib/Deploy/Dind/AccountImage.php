<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System\Privileged;

/**
 * The image every DinD account container runs: published with the engine, built from `templates/user/dind/project`
 * only when the pull fails. Prewarm puts it on the host at install and update.
 */
final class AccountImage
{
    public const REPOSITORY = 'ghcr.io/panelalpha/engine-user-dind';

    public const REF = self::REPOSITORY.':v2.2.0-20261028';

    /** Present, else pulled, else built from $context. */
    public static function ensureCommand(string $context): string
    {
        return self::inspectCommand().' || '.self::pullCommand().' || '.self::buildCommand($context);
    }

    /** The published image again; a failed pull keeps the one the host has. */
    public static function refreshCommand(): string
    {
        return self::pullCommand();
    }

    /**
     * The prewarm item every host warms, whatever `pae configure prewarm`
     * selected, and without charging the budget.
     *
     * @return array{id: string, ref: string, kind: string, runtime: string, prewarm: null, why: string, required: true}
     */
    public static function prewarmItem(): array
    {
        return [
            'id' => 'account',
            'ref' => self::REF,
            'kind' => HostPrewarmPlan::KIND_ACCOUNT,
            'runtime' => 'account',
            'prewarm' => null,
            'why' => 'every project runs in this container',
            'required' => true,
        ];
    }

    /**
     * Older tags of this image among $presentRefs, which no new account names.
     *
     * @param  list<string>  $presentRefs
     * @return list<string>
     */
    public static function superseded(array $presentRefs): array
    {
        return array_values(array_filter(
            $presentRefs,
            static fn (string $ref): bool => str_starts_with($ref, self::REPOSITORY.':') && $ref !== self::REF
        ));
    }

    /** @return list<string> */
    public static function presentArgv(): array
    {
        return Privileged::docker()->inspectImage(self::REF);
    }

    private static function inspectCommand(): string
    {
        return Privileged::dockerLines()->inspectImage(self::REF).' >/dev/null 2>&1';
    }

    private static function pullCommand(): string
    {
        return Privileged::dockerLines()->pullQuietly(self::REF).' >/dev/null';
    }

    private static function buildCommand(string $context): string
    {
        return Privileged::dockerLines()->buildQuietly(self::REF, $context).' >/dev/null';
    }
}
