<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Inspect\Report\StageSchedule;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;

/**
 * Which commands one stage runs: manifest and app config merged, or a {@see DeployPlan} replacing both.
 * No Laravel dependencies: unit-testable.
 */
final class StageResolver
{
    /**
     * One function, so {@see StageScript}, {@see HostScript} and the {@see StageSchedule} report that
     * predicts them cannot disagree.
     *
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
     * The manifest's commands, then the app config's; an app config command with a manifest
     * command's id replaces it rather than running beside it.
     *
     * @param  list<PlatformCommand>  $manifest
     * @param  list<PlatformCommand>  $own
     * @return list<PlatformCommand>
     */
    private static function merge(array $manifest, array $own): array
    {
        $replaced = array_map(static fn (PlatformCommand $c): string => $c->id, $own);
        $kept = array_filter(
            $manifest,
            static fn (PlatformCommand $c): bool => ! in_array($c->id, $replaced, true)
        );

        return array_merge(array_values($kept), $own);
    }

    /**
     * Whether the request took this stage over, which the entrypoint announces and the inspection
     * report labels, so a migration that never ran does not look like one that failed.
     */
    public static function isOverridden(string $stage, ?DeployPlan $plan): bool
    {
        return $plan !== null && $plan->definesStage($stage);
    }

    /**
     * Where one resolved command came from (`request`, `app_config` or `manifest`), compared by
     * identity rather than id, since a plan and a manifest may both call a command `migrate`.
     *
     * @param  list<PlatformCommand>  $appConfigCommands
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
