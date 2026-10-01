<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\StageScript;
use App\Lib\Deploy\ProjectSetup;

/**
 * The staged entrypoint, written next to the Dockerfile that installs it.
 *
 * The manifest supplies the stages; the deploy request may replace any of them
 * outright (see {@see \App\Lib\Deploy\Platform\DeployPlan}); the decision
 * supplies the commands as they were resolved for *this* project — the package manager the lockfile
 * chose, the binary name out of Cargo.toml. A project-level `setup` script
 * joins the install stage rather than keeping its own marker file: a marker
 * inside the image is wiped by the next rebuild, so it fired again on a
 * redeploy that was never a first install.
 */
class EntrypointWriter
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Which of the install-once or upgrade commands this boot should run.
     *
     * Only the engine can answer: the container cannot tell a first deploy
     * from a redeploy, and a marker file inside the image is wiped by the
     * next rebuild. Passed in as an environment variable that the generated
     * entrypoint branches on.
     *
     * @return array<string, string>
     */
    public function deployPhaseEnvironment(): array
    {
        return [
            PlatformStage::PHASE_ENV => PlatformStage::phaseFor(
                $this->dind->userModel()->getDeploymentStatus()
            ),
        ];
    }

    /**
     * Detection named a platform the registry cannot find (engine#169: a
     * worker's stale recipe list). No entrypoint follows, so every stage
     * command is dropped; this is the line that says so.
     *
     * @param array<string, mixed> $decision
     */
    public static function unresolvedPlatformWarning(array $decision, ?PlatformManifest $manifest): ?string
    {
        $platform = $decision['platform'] ?? null;
        if ($manifest !== null || !is_string($platform) || $platform === '') {
            return null;
        }

        return "Platform '{$platform}' was detected but could not be resolved;"
            . ' no entrypoint was written, so its install, upgrade and start commands will not run.';
    }

    /**
     * Copy the project's own entrypoint into place, if it ships one.
     *
     * Copied rather than pointed at, because the container runs
     * `panelalpha-entrypoint.sh` from the root of the mount and nothing
     * downstream should have to know which of the two wrote it.
     *
     * Returns whether it took over. Nothing about the generated script runs
     * when it does — that is the point of an override, and saying so in the
     * deploy log matters, because a project whose migrations stopped running
     * needs to know its own file is why.
     *
     * @param array<string, mixed> $decision
     */
    private function writeProjectOverride(string $projectDir, ?string $chown, array $decision): bool
    {
        $system = $this->dind->system();
        // Read from the checkout root: `.panelalpha/entrypoint.sh` is a
        // repository-level convention and stays where the project put it.
        // Only the destination follows the mount.
        $source = $projectDir . '/' . self::PROJECT_OVERRIDE;
        if (!$system->filesystem()->fileExists($source)) {
            return false;
        }

        $contents = $system->filesystem()->fileGetContents($source);
        if (!is_string($contents) || trim($contents) === '') {
            $this->dind->shell()->logger()?->info(
                self::PROJECT_OVERRIDE . ' is empty; using the generated entrypoint instead'
            );

            return false;
        }

        $this->dind->shell()->logger()?->info(
            'Using this project\'s own ' . self::PROJECT_OVERRIDE
            . ' — the platform\'s install, upgrade and start commands are not applied'
        );
        $system->filesystem()->filePutContents(
            AppRoot::path($projectDir, $decision) . '/' . StageScript::FILENAME,
            $contents,
            $chown,
            '755'
        );

        return true;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, array<string, string>> $prepend
     */
    /**
     * A project's own entrypoint, replacing the generated one outright.
     *
     * The staged entrypoint is assembled from a platform manifest, an app config
     * and the deploy request, and between them they cover what the engine
     * knows how to say. A project that needs something none of them can
     * express — a supervisor, a queue worker alongside the server, a wait on
     * something only it knows about — has had no way to say so short of
     * shipping its own Dockerfile and giving up the shared image with it.
     *
     * So: ship `.panelalpha/entrypoint.sh` and it is used verbatim. Under a
     * dot-directory rather than at the repository root because the engine's
     * own generated file already occupies `panelalpha-entrypoint.sh` there,
     * and a project overwriting that would be indistinguishable from the
     * engine's output on the next detect pass.
     */
    public const PROJECT_OVERRIDE = '.panelalpha/entrypoint.sh';

    /**
     * @return bool whether a script now exists at
     *         {@see StageScript::FILENAME}. False for a project that runs
     *         from its own compose file or Dockerfile, which has no generated
     *         entrypoint to write — a caller that has to *invoke* the script
     *         rather than bake it into an image needs to know which it got.
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
        $unresolved = self::unresolvedPlatformWarning($decision, $manifest);
        if ($unresolved !== null) {
            $this->dind->shell()->logger()?->warn($unresolved);
        }
        if ($manifest === null || $manifest->serveCommand() === null) {
            // A project that ships its own compose file or Dockerfile defines
            // its own runtime, so there is no generated entrypoint for a plan
            // to change. Saying so is the point: a deploy that quietly ignored
            // the commands it was given is worse than one that refused them.
            $runtimeStages = array_intersect($plan?->stages() ?? [], PlatformStage::RUNTIME_ORDER);
            if ($runtimeStages !== []) {
                $this->dind->shell()->logger()?->info(
                    'The deploy request set ' . implode(', ', $runtimeStages)
                    . ' commands, but this project runs from its own compose file or Dockerfile'
                    . ' — there is no generated entrypoint to put them in, so they were not applied.'
                );
            }

            return false;
        }

        $context = ProjectContext::make($projectDir, ProjectContext::listRootFiles($projectDir));

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
                // Said out loud rather than dropped silently: a project whose
                // `setup` script seeds demo data has to be able to find out
                // why it stopped running.
                $this->dind->shell()->logger()?->info(
                    'Skipping this project\'s own setup script: ' . $manifest->label
                    . ' resolves its dependencies during the build, on the host'
                );
            } else {
                $extraInstall['project-setup'] =
                    'APP_ENV=local RAILS_ENV=development NODE_ENV=development ' . $setup;
            }
        }

        // Into the subtree the compose file mounts at /app, not the checkout
        // root: the base image looks for /app/panelalpha-entrypoint.sh, and a
        // manifest with an app_root mounts only that subtree. Written to the
        // root it is invisible to the container, the shim falls through to
        // "serving directly", and every stage command is dropped in silence.
        $this->dind->system()->filesystem()->filePutContents(
            AppRoot::path($projectDir, $decision) . '/' . StageScript::FILENAME,
            StageScript::render(
                $manifest,
                $context,
                $overrides,
                $extraInstall,
                $prepend,
                $appConfig,
                $plan
            ),
            $chown,
            '755'
        );

        return true;
    }
}
