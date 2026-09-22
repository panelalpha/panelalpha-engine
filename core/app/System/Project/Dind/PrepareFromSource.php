<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Git\Exception as GitException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Telemetry\DetectionSignal;
use App\Lib\Deploy\Telemetry\Telemetry;

/**
 * Turning a checkout into something the account can run: bootstrap it from
 * its app config, work out what it is, record that, then apply the matching
 * strategy.
 *
 * The order is deliberate and has been wrong before. The app config runs *first*
 * and edits the checkout — file snippets, its own compose file, its
 * after-clone script — so that by the time detection looks, a compose file
 * the page wrote and one the repository shipped are the same file. Detecting
 * first would decide against a tree that is about to change underneath it.
 */
class PrepareFromSource
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Shared by git clone and zip import — which is the point: a project
     * deployed from an upload gets the same treatment as a cloned one.
     */
    public function prepare(): void
    {
        $user = $this->dind->userModel();
        $projectDir = $this->dind->userAppDirPath();
        $chown = $user->getChownString();
        $logger = $this->dind->shell()->logger();
        $gitRepo = $user->getGitRepo();

        // Clone/import already finished; detect + host compile + image
        // build belong to "running", not "cloning".
        $logger?->stage(DeployLogger::STAGE_RUNNING);

        $appConfig = $this->dind->appConfig($gitRepo);
        $this->dind->strategy()->bootstrap($appConfig, $projectDir, $chown);

        $this->dropSupersededPlaceholder($projectDir, $logger);

        $recipe = app(RecipeChoiceContext::class)->get();
        $decision = DetectProjectStrategy::detect($projectDir, $gitRepo, $recipe);
        if ($recipe !== null) {
            $logger?->info("Deploy request pinned the recipe: {$recipe}");
        }

        // The build stage becomes RUN layers in the generated Dockerfile
        // rather than lines in the entrypoint, and the Dockerfile writers read
        // those off the decision. A plan that spoke for `build` therefore has
        // to be applied here, before anything reads the decision.
        $plan = app(DeployPlanContext::class)->get();
        if ($plan !== null) {
            $decision = $plan->applyToDecision($decision);
            $logger?->info(
                'Deploy request replaced these stages: ' . implode(', ', $plan->stages())
            );
        }

        try {
            DeployabilityCheck::assert($decision, $projectDir);
            if ($gitRepo !== null) {
                $this->assertGitHeadReadable($projectDir);
            }
        } catch (\InvalidArgumentException $e) {
            $logger?->info($e->getMessage());
            throw $e;
        }

        $this->dind->freezeDeploySnapshot([
            'deploy_source' => $gitRepo !== null ? 'git' : 'archive',
            'deploy_strategy' => $decision['strategy'],
            'deploy_label' => $decision['label'],
            'deploy_port' => $decision['port_hint'],
            'deploy_runtime' => $decision['runtime'] ?? null,
            // Which manifest claimed the project, as opposed to which
            // strategy it shares: `html` and `static` are both the static
            // strategy, and only the manifest knows what to check.
            'deploy_platform' => is_string($decision['platform'] ?? null) ? $decision['platform'] : null,
            // Where the recipe that claimed it keeps its own health checks, so
            // they can still be found at health-check time. Frozen rather than
            // re-derived from the URL: the account keeps its repository, and a
            // directory renamed between the deploy and the check would
            // otherwise take the checks with it -- silently, again.
            //
            // The `checks/` subdirectory, not the recipe directory: that is the
            // shape `CheckRegistry::allWithDirectory()` reads, and it is null
            // for every recipe that ships none, which is most of them.
            'deploy_checks_dir' => ($dir = SourceRecipes::directoryFor($gitRepo)) === null
                ? null
                : SourceRecipes::checksDirectory($dir),
            // The resolved image, not the strategy's default one. What gets
            // seeded has to be what the project asked for.
            'deploy_image' => is_string($decision['image'] ?? null) ? $decision['image'] : null,
            'git_commit' => $gitRepo !== null ? $this->readGitCommit($projectDir) : null,
        ]);

        $sourceRecipe = $decision['source_recipe'] ?? null;
        if (is_string($sourceRecipe)) {
            $logger?->info("Recipe named by {$sourceRecipe}");
        }
        $logger?->info("Detected project type: {$decision['label']}");
        $logger?->info("Using strategy: {$decision['strategy']}");

        // A compose file the repo ships but ComposeUsableProbe skipped as a
        // workstation dev compose is otherwise invisible: name the mount that
        // demoted it so a wrong strategy is one log line, not a silent hunt.
        if ($decision['strategy'] !== Strategies::COMPOSE
            && ($composePath = ComposeFileInspector::firstIn($projectDir)) !== null
            && ($reason = ComposeFileInspector::localDevComposeReason($composePath)) !== null
        ) {
            $logger?->info(sprintf(
                'Compose file %s looks like a workstation dev compose (%s); using strategy %s instead',
                basename($composePath),
                $reason,
                $decision['strategy']
            ));
        }

        // Detection reaching past the manifests is not a failure — Railpack
        // usually builds the project, and where even it has nothing to go on
        // the fallback compose still serves something. Either way nobody has
        // written a recipe for whatever this project is, and left unreported
        // that is invisible: the deploy goes green and the gap is only ever
        // found by a support ticket.
        $unreciped = DetectionSignal::forDecision($decision, $projectDir);
        if ($unreciped !== null) {
            Telemetry::signal($user->username, $unreciped['signal'], $unreciped['detail']);
        }

        $sourceLabel = $gitRepo !== null ? GitUrl::sanitize($gitRepo) : 'uploaded archive';
        $this->dind->strategy()->apply($decision, $appConfig, $projectDir, $chown, $sourceLabel);
        $this->dind->strategy()->installRailsHostInitializer($projectDir, $chown);

        if ($gitRepo !== null || is_dir($projectDir . '/.git')) {
            $this->gitForProjectDir($projectDir)->allowUntrustedGitDirectory();
        }

        $this->dind->networking()->detectAndCreateProxyRules($user);
        $this->dind->applyProjectEnvVars();
    }

    /**
     * Run the precheck stage: validation that should stop a deploy before it
     * costs a clone.
     *
     * The app config contributes here, and so does a recipe written for this
     * repository — the one kind of manifest that can be chosen before the
     * source exists, since it is found by the clone URL rather than by
     * reading the checkout. Everything else still cannot: detection reads
     * files, and there are none yet. {@see PlatformStage::PRECHECK}
     */
    public function preCheck(): void
    {
        $gitRepo = $this->dind->userModel()->getGitRepoOrFail();
        $appConfig = $this->dind->appConfig($gitRepo);
        // The app config is loaded already, and at this point it is the engine's:
        // the repository has not been cloned, so it cannot have shipped one.
        $recipe = SourceRecipes::fromAppConfig($appConfig, AppConfig::YAML_FILENAME);
        $plan = app(DeployPlanContext::class)->get();

        // Staged commands keep `before`, `optional`, `timeout` and `when`.
        // The hook script has none of that to keep, so it stays a raw body and
        // runs last, which is where it has always run.
        $staged = $appConfig?->commands(PlatformStage::PRECHECK) ?? [];
        $extra = [];
        $commands = $appConfig?->preCheckCommands();
        if (is_string($commands) && $commands !== '') {
            $extra[AppConfig::PRE_CHECK_SCRIPT] = $commands;
        }
        // A deploy request can put commands here for a project that ships
        // neither an app config nor a source recipe.
        if (HostScript::isEmpty($recipe, PlatformStage::PRECHECK, null, $extra, $staged, $plan)) {
            return;
        }

        // No project on disk yet — this runs before the clone — so there is no
        // context to evaluate a `when` guard against, and every command stands.
        $script = HostScript::render($recipe, PlatformStage::PRECHECK, null, [], $extra, $staged, $plan);

        $path = $this->dind->homeDirPath() . '/' . AppConfig::PRE_CHECK_SCRIPT;
        $chown = $this->dind->userModel()->getChownString();
        $this->dind->system()->filesystem()->filePutContents($path, $script, $chown, '644');
        $this->dind->shell()->execAsUser(['bash', $path]);
        $this->dind->shell()->exec(['rm', $path]);
    }

    private function assertGitHeadReadable(string $projectDir): void
    {
        try {
            $this->gitForProjectDir($projectDir)->assertHeadReadable();
        } catch (GitException $e) {
            throw new \InvalidArgumentException(
                'Git HEAD is not readable after clone; repository may be empty or corrupt.'
            );
        }
    }

    private function readGitCommit(string $projectDir): ?string
    {
        return $this->gitForProjectDir($projectDir)->readHeadCommit();
    }

    private function gitForProjectDir(string $projectDir): GitRepository
    {
        return GitRepository::forProjectDir($this->dind, $projectDir);
    }

    /**
     * Drop the welcome page once the account has an application of its own.
     *
     * Loading a source removes it already -- a clone empties ~/project first,
     * an archive is rsynced with --delete -- but files written in one at a
     * time are never "loaded" at all, so nothing else ever decides the
     * placeholder has been superseded. Leaving it means the engine's own page
     * competes with the user's index for the document root, and detection
     * reads it as the site's own document.
     */
    private function dropSupersededPlaceholder(string $projectDir, ?DeployLogger $logger): void
    {
        $index = PlaceholderPage::supersededIndex($projectDir);
        if ($index === null) {
            return;
        }

        $this->dind->system()->exec(['sudo', 'rm', '-f', $index]);
        $logger?->info('Removed the placeholder page: the project has files of its own');
    }
}
