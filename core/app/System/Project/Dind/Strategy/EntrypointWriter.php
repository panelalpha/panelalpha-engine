<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\StageScript;
use App\Lib\Deploy\ProjectSetup;
use App\System\Project\Dind as DindProject;

/**
 * The staged entrypoint: the manifest's stages, any the deploy request replaces ({@see DeployPlan}), and the
 * commands the decision resolved for this project, with a project `setup` script joining the install stage.
 */
class EntrypointWriter
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Which of the install-once or upgrade commands this boot runs, as the variable the entrypoint branches on:
     * only the engine can tell a first deploy from a redeploy, since a marker in the image dies with a rebuild.
     *
     * @return array<string, string>
     */
    public function deployPhaseEnvironment(): array
    {
        return [
            PlatformStage::PHASE_ENV => PlatformStage::phaseFor(
                $this->dind->userModel()->getDeploymentStatus(),
                $this->dind->userModel()->hasDeployedBefore(),
            ),
        ];
    }

    /**
     * The line saying every stage command is dropped because detection named a platform the registry cannot find.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function unresolvedPlatformWarning(array $decision, ?PlatformManifest $manifest): ?string
    {
        $platform = $decision['platform'] ?? null;
        if ($manifest !== null || ! is_string($platform) || $platform === '') {
            return null;
        }

        return "Platform '{$platform}' was detected but could not be resolved;"
            .' no entrypoint was written, so its install, upgrade and start commands will not run.';
    }

    /**
     * Copies the project's own entrypoint, read from the checkout root, to where the mount runs the generated one,
     * and says so in the log; returns whether it took over.
     *
     * @param  array<string, mixed>  $decision
     */
    private function writeProjectOverride(string $projectDir, ?string $chown, array $decision): bool
    {
        $system = $this->dind->system();
        $source = $projectDir.'/'.self::PROJECT_OVERRIDE;
        if (! $system->filesystem()->fileExists($source)) {
            return false;
        }

        $contents = $system->filesystem()->fileGetContents($source);
        if (! is_string($contents) || trim($contents) === '') {
            $this->dind->shell()->logger()?->info(
                self::PROJECT_OVERRIDE.' is empty; using the generated entrypoint instead'
            );

            return false;
        }

        $this->dind->shell()->logger()?->info(
            'Using this project\'s own '.self::PROJECT_OVERRIDE
            .' — the platform\'s install, upgrade and start commands are not applied'
        );
        $system->filesystem()->writeFileReplacingPath(
            AppRoot::path($projectDir, $decision).'/'.StageScript::FILENAME,
            $contents,
            $chown,
            '755'
        );

        return true;
    }

    /** Used verbatim in place of the generated entrypoint, whose own name at the root it must not take over. */
    public const PROJECT_OVERRIDE = '.panelalpha/entrypoint.sh';

    /**
     * Written into the subtree the compose file mounts at /app, where the base image looks for it.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, array<string, string>>  $prepend
     * @return bool whether a script now exists at {@see StageScript::FILENAME}; false for a project
     *              that runs from its own compose file or Dockerfile, which has no generated entrypoint
     */
    public function write(
        array $decision,
        string $projectDir,
        ?string $chown,
        array $prepend = [],
        ?AppConfig $appConfig = null
    ): bool {
        if ($this->writeProjectOverride($projectDir, $chown, $decision)) {
            return true;
        }

        $plan = app(DeployPlanContext::class)->get();
        $manifest = PlatformRegistry::forDecisionOrAppConfig($decision, $appConfig);
        $this->warnIfUnresolved($decision, $manifest);
        if ($manifest === null || $manifest->serveCommand() === null) {
            $this->reportUnappliedRuntimeStages($plan);

            return false;
        }

        $context = ProjectContext::make($projectDir, ProjectContext::listRootFiles($projectDir));
        $overrides = $this->commandOverrides($decision, $manifest);
        $extraInstall = $this->projectSetupInstall($projectDir, $manifest);
        $this->dind->system()->filesystem()->writeFileReplacingPath(
            AppRoot::path($projectDir, $decision).'/'.StageScript::FILENAME,
            StageScript::render($manifest, $context, $overrides, $extraInstall, $prepend, $appConfig, $plan),
            $chown,
            '755'
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function warnIfUnresolved(array $decision, ?PlatformManifest $manifest): void
    {
        $unresolved = self::unresolvedPlatformWarning($decision, $manifest);
        if ($unresolved !== null) {
            $this->dind->shell()->logger()?->warn($unresolved);
        }
    }

    /** A project on its own compose file or Dockerfile has no entrypoint for a plan to change, and is told so. */
    private function reportUnappliedRuntimeStages(?DeployPlan $plan): void
    {
        $runtimeStages = array_intersect($plan?->stages() ?? [], PlatformStage::RUNTIME_ORDER);
        if ($runtimeStages !== []) {
            $this->dind->shell()->logger()?->info(
                'The deploy request set '.implode(', ', $runtimeStages)
                .' commands, but this project runs from its own compose file or Dockerfile'
                .' — there is no generated entrypoint to put them in, so they were not applied.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, string>
     */
    private function commandOverrides(array $decision, PlatformManifest $manifest): array
    {
        $overrides = [];
        foreach ($decision['resolved_commands'] ?? [] as $id => $run) {
            if (is_string($id) && is_string($run) && trim($run) !== '') {
                $overrides[$id] = $run;
            }
        }
        $serve = $manifest->serveCommand();
        $start = trim((string) ($decision['start_command'] ?? ''));
        if ($start !== '') {
            $overrides[$serve->id] = $start;
        }

        return $overrides;
    }

    /**
     * The project's own `setup` script for the install stage; skipped out loud where the platform resolves
     * dependencies in its build, so a project whose seed stopped running can find out why.
     *
     * @return array<string, string>
     */
    private function projectSetupInstall(string $projectDir, PlatformManifest $manifest): array
    {
        $extraInstall = [];
        $files = $this->dind->projectTree();
        $setup = ProjectSetup::command(
            $files->readIn($projectDir, 'composer.json'),
            $files->readIn($projectDir, 'package.json'),
            ProjectContext::listRootFiles($projectDir)
        );
        if (is_string($setup) && trim($setup) !== '') {
            if (ProjectSetup::isSupersededByPlatform(
                $manifest->runtime,
                $manifest->stage(PlatformStage::BUILD) !== []
            )) {
                $this->dind->shell()->logger()?->info(
                    'Skipping this project\'s own setup script: '.$manifest->label
                    .' resolves its dependencies during the build, on the host'
                );
            } else {
                $extraInstall['project-setup'] =
                    'APP_ENV=local RAILS_ENV=development NODE_ENV=development '.$setup;
            }
        }

        return $extraInstall;
    }
}
