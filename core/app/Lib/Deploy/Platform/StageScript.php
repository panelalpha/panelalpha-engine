<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Stage\CommandScript;
use App\Lib\Deploy\Platform\Stage\ServeCommand;
use App\Lib\Deploy\Platform\Stage\StageBlock;
use App\Lib\Deploy\Platform\Stage\WorkingDirectory;
use App\Lib\Deploy\Template\Template;

/**
 * The container entrypoint: the install or upgrade block by {@see PlatformStage::PHASE_ENV} (upgrade when unset,
 * so seeders never re-run over live rows), then start, then `exec` of the serve command.
 */
final class StageScript
{
    public const FILENAME = 'panelalpha-entrypoint.sh';

    /** @var list<string> */
    private const PHASE_STAGES = [PlatformStage::INSTALL, PlatformStage::UPGRADE];

    /**
     * @param  ?ProjectContext  $context  project to evaluate `when` guards against; null keeps every command
     * @param  array<string, string>  $overrides  command id => the command as resolved for this project
     * @param  array<string, string>  $extraInstall  install commands discovered in the project, such as `bin/setup`
     * @param  array<string, array<string, string>>  $prepend  stage => engine commands run first, kept under a plan
     * @param  ?AppConfig  $appConfig  the project's app config, whose `serve` replaces the platform's
     * @param  ?DeployPlan  $plan  a stage it names replaces that stage's manifest, app config and extra commands
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
            'platform' => $manifest->id.' ('.$manifest->label.')',
            'phase_env' => PlatformStage::PHASE_ENV,
            'default_phase' => PlatformStage::UPGRADE,
            'stage_blocks' => self::stageBlocks($manifest, $context, $overrides, $extraInstall, $prepend, $appConfig, $plan),
            'start_commands' => self::startCommands($manifest, $context, $overrides, $appConfig, $plan),
            'serve' => self::serve($manifest, $overrides, $appConfig, $plan, $context),
        ]);
    }

    /**
     * Build-stage commands as Dockerfile RUN lines, split by layer so the dependency install is cached above the source copy.
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
            $layers[self::layerOf($command)][] = $command->optional ? $run.' || true' : $run;
        }

        return $layers;
    }

    private static function layerOf(PlatformCommand $command): string
    {
        return $command->role === PlatformCommand::ROLE_DEPENDENCIES ? 'dependencies' : 'assets';
    }

    /**
     * A request that replaced the install stage drops the project's own `setup` script too: it is a default like any other.
     *
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $extraInstall
     * @param  array<string, array<string, string>>  $prepend
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
                $stage === PlatformStage::INSTALL && ! $overridden ? $extraInstall : [],
                $overridden
            );
            if (! $block->isEmpty()) {
                $blocks[] = $block->render();
                $blocks[] = '';
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, string>  $overrides
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
            if (! $command->serve) {
                $lines = array_merge(
                    $lines,
                    (new CommandScript($command, PlatformStage::START, $overrides))->lines()
                );
            }
        }

        return $lines;
    }

    /**
     * An app config's own `serve` command replaces the platform's: correcting how a named platform starts is a
     * smaller statement than taking the whole deployment over.
     *
     * @param  array<string, string>  $overrides
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
            return self::replacedServe($plan);
        }

        $own = $appConfig?->serveCommand();
        if ($own !== null) {
            return self::ownServe($manifest, $own, $context);
        }
        $serve = $manifest->serveCommand();

        return $serve === null ? [] : (new ServeCommand($serve, $overrides))->lines();
    }

    /**
     * A replaced start stage with nothing marked `serve` fails loudly: falling off the script's end exits 0,
     * which reads as a clean shutdown and restarts forever without saying why.
     *
     * @return list<string>
     */
    private static function replacedServe(?DeployPlan $plan): array
    {
        $serve = self::serveIn($plan?->commandsFor(PlatformStage::START) ?? []);

        return $serve === null
            ? [
                "pa_step start 'no serve command'",
                "printf '[panelalpha] the deploy request replaced the start stage without a serve"
                ." command; there is nothing to run\\n' >&2",
                'exit 1',
            ]
            : (new ServeCommand($serve, []))->lines();
    }

    /**
     * Resolved for this project rather than through the overrides: those are keyed by the platform serve's id,
     * and a recipe serve sharing that id ran the package.json default instead of its own.
     *
     * @return list<string>
     */
    private static function ownServe(PlatformManifest $manifest, PlatformCommand $own, ?ProjectContext $context): array
    {
        $run = $context === null ? $own->run : PlatformValues::resolveJsPlaceholder($manifest, $context, $own->run);

        return (new ServeCommand($own, [$own->id => $run]))->lines();
    }

    /**
     * @param  list<PlatformCommand>  $commands
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
