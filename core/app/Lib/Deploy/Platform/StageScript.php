<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Stage\CommandScript;
use App\Lib\Deploy\Platform\Stage\ServeCommand;
use App\Lib\Deploy\Platform\Stage\StageBlock;
use App\Lib\Deploy\Platform\Stage\WorkingDirectory;
use App\Lib\Deploy\Template\Template;

/**
 * Renders a manifest's runtime stages into the container entrypoint.
 *
 * This is where the stage vocabulary stops being metadata and starts being
 * behaviour. The generated script reads {@see PlatformStage::PHASE_ENV}, runs
 * the install *or* upgrade block accordingly, then always runs start and
 * `exec`s the serve command — so a restart re-runs `optimize` and nothing
 * else, while a redeploy that changed the code runs the migration first.
 *
 * It defaults to the upgrade phase when the engine did not say. Guessing
 * "install" for an account that already holds data would re-run seeders over
 * live rows; guessing "upgrade" for a fresh one merely skips a first-boot
 * convenience that the next deploy repeats.
 *
 * No Laravel dependencies — unit-testable.
 */
final class StageScript
{
    public const FILENAME = 'panelalpha-entrypoint.sh';

    /** @var list<string> */
    private const PHASE_STAGES = [PlatformStage::INSTALL, PlatformStage::UPGRADE];

    /**
     * @param ?ProjectContext $context project to evaluate `when` guards
     *        against; null keeps every command
     * @param array<string, string> $overrides command id => the command as
     *        resolved for this project, replacing the manifest's default
     *        (a `{{js.start:…}}` placeholder is the manifest speaking in
     *        generalities; by render time the project has answered)
     * @param array<string, string> $extraInstall install-stage commands the
     *        caller discovered rather than the manifest — a `setup` script in
     *        package.json or composer.json, `bin/setup`
     * @param array<string, array<string, string>> $prepend stage => commands
     *        to run before the manifest's own
     * @param ?AppConfig $appConfig the project's own app config, whose staged
     *        commands run alongside the platform's — ahead of them where it
     *        said `before`, and whose `serve` command replaces the platform's
     *        entrypoint outright
     * @param ?DeployPlan $plan what this deploy was told to run. A stage it
     *        names replaces that stage entirely — manifest, app config and the
     *        `extra` commands discovered from the project alike. What the
     *        engine prepends survives it: only the engine knows whether this
     *        account's migration has a MySQL sidecar to wait for
     */
    public static function render(
        PlatformManifest $manifest,
        ?ProjectContext $context = null,
        array $overrides = [],
        array $extraInstall = [],
        array $prepend = [],
        ?AppConfig $appConfig = null,
        ?DeployPlan $plan = null
    ): string {
        return Template::named('script/entrypoint')->render([
            'source' => $manifest->source,
            'platform' => $manifest->id . ' (' . $manifest->label . ')',
            'phase_env' => PlatformStage::PHASE_ENV,
            'default_phase' => PlatformStage::UPGRADE,
            'stage_blocks' => self::stageBlocks($manifest, $context, $overrides, $extraInstall, $prepend, $appConfig, $plan),
            'start_commands' => self::startCommands($manifest, $context, $overrides, $appConfig, $plan),
            'serve' => self::serve($manifest, $overrides, $appConfig, $plan, $context),
        ]);
    }

    /**
     * Build-stage commands as Dockerfile RUN lines, split by layer so the
     * dependency install can be cached above the source copy.
     *
     * @return array{dependencies: list<string>, assets: list<string>}
     */
    public static function buildLayers(
        PlatformManifest $manifest,
        ?ProjectContext $context = null,
        ?DeployPlan $plan = null
    ): array {
        $layers = ['dependencies' => [], 'assets' => []];

        foreach (self::commandsFor($manifest, PlatformStage::BUILD, $context, null, $plan) as $command) {
            $run = WorkingDirectory::wrap($command->run, $command->workdir);
            $layers[self::layerOf($command)][] = $command->optional ? $run . ' || true' : $run;
        }

        return $layers;
    }

    private static function layerOf(PlatformCommand $command): string
    {
        return $command->role === PlatformCommand::ROLE_DEPENDENCIES ? 'dependencies' : 'assets';
    }

    /**
     * @param array<string, string> $overrides
     * @param array<string, string> $extraInstall
     * @param array<string, array<string, string>> $prepend
     * @return list<string>
     */
    private static function stageBlocks(
        PlatformManifest $manifest,
        ?ProjectContext $context,
        array $overrides,
        array $extraInstall,
        array $prepend,
        ?AppConfig $appConfig,
        ?DeployPlan $plan
    ): array {
        $blocks = [];
        foreach (self::PHASE_STAGES as $stage) {
            $overridden = StageResolver::isOverridden($stage, $plan);
            $block = new StageBlock(
                $stage,
                self::commandsFor($manifest, $stage, $context, $appConfig, $plan),
                $overrides,
                $prepend[$stage] ?? [],
                // A project's own `setup` script is a default like any other,
                // so a request that spoke for the install stage replaces it
                // too. Leaving it in would make "install runs exactly this"
                // false for the one command nobody listed.
                $stage === PlatformStage::INSTALL && !$overridden ? $extraInstall : [],
                $overridden
            );
            if (!$block->isEmpty()) {
                $blocks[] = $block->render();
                $blocks[] = '';
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private static function startCommands(
        PlatformManifest $manifest,
        ?ProjectContext $context,
        array $overrides,
        ?AppConfig $appConfig,
        ?DeployPlan $plan
    ): array {
        $lines = [];
        if (StageResolver::isOverridden(PlatformStage::START, $plan)) {
            $lines[] = "pa_step start 'replaced by the deploy request'";
        }
        foreach (self::commandsFor($manifest, PlatformStage::START, $context, $appConfig, $plan) as $command) {
            if (!$command->serve) {
                $lines = array_merge(
                    $lines,
                    (new CommandScript($command, PlatformStage::START, $overrides))->lines()
                );
            }
        }

        return $lines;
    }

    /**
     * An app config's own `serve` command replaces the platform's. Naming a
     * platform and then correcting how it starts is a smaller statement than
     * taking the whole deployment over, and used to be impossible.
     *
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private static function serve(
        PlatformManifest $manifest,
        array $overrides,
        ?AppConfig $appConfig,
        ?DeployPlan $plan,
        ?ProjectContext $context = null
    ): array {
        if (StageResolver::isOverridden(PlatformStage::START, $plan)) {
            $serve = self::serveIn($plan?->commandsFor(PlatformStage::START) ?? []);

            // A replaced start stage with nothing marked `serve` leaves the
            // container with no process to become. That is the caller's call
            // to make, but it must not be a silent one: falling off the end of
            // the script exits 0, which reads as a clean shutdown and restarts
            // forever without ever saying why.
            return $serve === null
                ? [
                    "pa_step start 'no serve command'",
                    "printf '[panelalpha] the deploy request replaced the start stage without a serve"
                    . " command; there is nothing to run\\n' >&2",
                    'exit 1',
                ]
                : (new ServeCommand($serve, []))->lines();
        }

        $own = $appConfig?->serveCommand();
        if ($own !== null) {
            // Stated for this project on purpose. The overrides are the
            // platform serve's resolution, keyed by id, and a recipe serve that
            // shared that id ran the package.json default instead of its own.
            $run = $context === null ? $own->run : PlatformValues::resolveJsPlaceholder($manifest, $context, $own->run);

            return (new ServeCommand($own, [$own->id => $run]))->lines();
        }
        $serve = $manifest->serveCommand();

        return $serve === null ? [] : (new ServeCommand($serve, $overrides))->lines();
    }

    /**
     * @param list<PlatformCommand> $commands
     */
    private static function serveIn(array $commands): ?PlatformCommand
    {
        foreach ($commands as $command) {
            if ($command->serve) {
                return $command;
            }
        }

        return null;
    }

    /**
     * @return list<PlatformCommand>
     */
    private static function commandsFor(
        PlatformManifest $manifest,
        string $stage,
        ?ProjectContext $context,
        ?AppConfig $appConfig = null,
        ?DeployPlan $plan = null
    ): array {
        return StageResolver::commandsFor($stage, $manifest, $appConfig, $plan, $context);
    }
}
