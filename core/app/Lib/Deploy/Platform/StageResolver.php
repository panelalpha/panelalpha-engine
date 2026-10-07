<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;

/**
 * Which commands one stage runs, from the three sources that may speak for it.
 *
 * The order is a hierarchy of how specific the speaker is. A platform manifest
 * knows what a Laravel app is. An app config knows what *this* application is. A
 * {@see DeployPlan} knows what *this deploy* is being asked to do, and being
 * the most specific it does not merge with the other two — it replaces the
 * stage.
 *
 * This exists as one function because the answer is consumed in three places —
 * the container entrypoint ({@see StageScript}), the account-side scripts
 * ({@see HostScript}) and the inspection report
 * ({@see \App\Lib\Deploy\Inspect\Report\StageSchedule}) — and the third one is
 * a *prediction of the first two*. Three copies of this logic would eventually
 * disagree, and the shape of that bug is an endpoint confidently reporting a
 * plan the deploy does not follow.
 *
 * No Laravel dependencies — unit-testable.
 */
final class StageResolver
{
    /**
     * @return list<PlatformCommand>
     */
    public static function commandsFor(
        string $stage,
        ?PlatformManifest $manifest = null,
        ?AppConfig $appConfig = null,
        ?DeployPlan $plan = null,
        ?ProjectContext $context = null
    ): array {
        $commands = $plan !== null && $plan->definesStage($stage)
            ? $plan->commandsFor($stage)
            : self::merge($manifest?->stage($stage) ?? [], $appConfig?->commands($stage) ?? []);

        if ($context !== null) {
            $commands = PlatformMatcher::applicable($commands, $context);
        }

        return PlatformCommand::ordered($commands);
    }

    /**
     * The manifest's commands, then the app config's. An app config command
     * with a manifest command's id replaces it rather than running beside it:
     * a recipe restating `composer-install` means "instead of", and running
     * both paid for the dependency tree twice.
     *
     * @param list<PlatformCommand> $manifest
     * @param list<PlatformCommand> $own
     * @return list<PlatformCommand>
     */
    private static function merge(array $manifest, array $own): array
    {
        $replaced = array_map(static fn (PlatformCommand $c): string => $c->id, $own);
        $kept = array_filter(
            $manifest,
            static fn (PlatformCommand $c): bool => !in_array($c->id, $replaced, true)
        );

        return array_merge(array_values($kept), $own);
    }

    /**
     * Whether the request took this stage over, which the entrypoint announces
     * and the inspection report labels. Silence here is how a support ticket
     * turns into an afternoon: an account whose migration never ran looks
     * exactly like an account whose migration failed, unless something says
     * the stage was replaced.
     */
    public static function isOverridden(string $stage, ?DeployPlan $plan): bool
    {
        return $plan !== null && $plan->definesStage($stage);
    }

    /**
     * Where one resolved command came from: `request` when the plan replaced
     * the stage, `app_config` when the project's own descriptor contributed it,
     * `manifest` otherwise.
     *
     * Compared by identity rather than by id, because a plan and a manifest
     * may legitimately both call a command `migrate` — the question is which
     * object survived the resolution, not which name it carries.
     *
     * @param list<PlatformCommand> $appConfigCommands
     */
    public static function sourceOf(
        PlatformCommand $command,
        string $stage,
        ?DeployPlan $plan,
        array $appConfigCommands = []
    ): string {
        if (self::isOverridden($stage, $plan)) {
            return 'request';
        }

        foreach ($appConfigCommands as $candidate) {
            if ($candidate === $command) {
                return 'app_config';
            }
        }

        return 'manifest';
    }
}
