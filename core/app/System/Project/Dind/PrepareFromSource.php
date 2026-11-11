<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigStack;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Telemetry\DetectionSignal;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Models\User as ModelsUser;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use App\System\Project\Git\Exception as GitException;

/**
 * Turning a checkout into something the account can run. The app config bootstraps it first
 * and may edit the checkout, so detection sees the tree as it will be deployed.
 */
class PrepareFromSource
{
    /** Hooks explain a refusal on stdout, and the failure message is built from stderr. */
    private const RUN_WITH_STDOUT_ON_STDERR = 'exec bash "$0" 1>&2';

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Shared by git clone and zip import. Credentials reach the app config's prepare hook, which may read them; from
     * detection on, the app config speaks only for its own stack ({@see AppConfigStack}) but its credentials stay.
     */
    public function prepare(): void
    {
        $user = $this->dind->userModel();
        $projectDir = $this->dind->userAppDirPath();
        $chown = $user->getChownString();
        $logger = $this->dind->shell()->logger();
        $gitRepo = $user->getGitRepo();

        $logger?->stage(DeployLogger::STAGE_RUNNING);

        $appConfig = $this->dind->appConfig($gitRepo);
        app(ProjectPublicRouting::class)->prepare($user, $appConfig?->publicRouting() ?? PublicRouting::none(), $logger);
        $this->dind->appCredentials()->deliver($appConfig?->credentials(), final: false);
        $this->dind->strategy()->bootstrap($appConfig, $projectDir, $chown);

        $this->dropSupersededPlaceholder($projectDir, $logger);

        $decision = $this->detectDecision($projectDir, $gitRepo, $logger);
        $this->assertDeployable($decision, $projectDir, $gitRepo, $logger);
        $stackConfig = AppConfigStack::for($decision, $appConfig);
        $this->freezeSnapshot($decision, $stackConfig, $stackConfig === $appConfig, $projectDir, $gitRepo);
        $this->logDecision($decision, $logger);
        $this->explainIgnoredComposeFile($decision, $projectDir, $logger);
        $this->warnInertDatabase($decision, $logger);
        $this->reportUnreciped($user->username, $decision, $projectDir);
        $this->deliverManifestCredentials($decision, $appConfig);

        $this->applyDecision($user, $decision, $stackConfig, $projectDir, $gitRepo);
    }

    /**
     * Detect the project, then let the deploy request's plan replace stages. The Dockerfile
     * writers read the `build` stage off the decision, so the plan is applied before anything reads it.
     *
     * @return array<string, mixed>
     */
    private function detectDecision(string $projectDir, ?string $gitRepo, ?DeployLogger $logger): array
    {
        $recipe = app(RecipeChoiceContext::class)->get();
        $decision = DetectProjectStrategy::detect($projectDir, $gitRepo, $recipe);
        if ($recipe !== null) {
            $logger?->info("Deploy request pinned the recipe: {$recipe}");
        }

        $plan = app(DeployPlanContext::class)->get();
        if ($plan !== null) {
            $decision = $plan->applyToDecision($decision);
            $logger?->info(
                'Deploy request replaced these stages: '.implode(', ', $plan->stages())
            );
        }

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function assertDeployable(array $decision, string $projectDir, ?string $gitRepo, ?DeployLogger $logger): void
    {
        try {
            DeployabilityCheck::assert($decision, $projectDir);
            if ($gitRepo !== null) {
                $this->assertGitHeadReadable($projectDir);
            }
        } catch (\InvalidArgumentException $e) {
            $logger?->info($e->getMessage());
            throw $e;
        }
    }

    /**
     * `deploy_platform` is the manifest that claimed it (`html` and `static` share a strategy). The rest is frozen,
     * not re-derived, so a project list reads no checkout.
     *
     * @param  array<string, mixed>  $decision
     * @param  ?AppConfig  $stackConfig  the app config when it is written for this stack
     */
    private function freezeSnapshot(array $decision, ?AppConfig $stackConfig, bool $appConfigFits, string $projectDir, ?string $gitRepo): void
    {
        $this->dind->freezeDeploySnapshot([
            'deploy_source' => $gitRepo !== null ? 'git' : 'archive',
            'deploy_strategy' => $decision['strategy'],
            'deploy_label' => $decision['label'],
            'deploy_port' => $decision['port_hint'],
            'deploy_runtime' => $decision['runtime'] ?? null,
            'deploy_platform' => is_string($decision['platform'] ?? null) ? $decision['platform'] : null,
            'deploy_checks_dir' => $this->recipeChecksDirectory($gitRepo),
            'deploy_image' => is_string($decision['image'] ?? null) ? $decision['image'] : null,
            AppHealth::DETAIL_START_PERIOD => $stackConfig?->startPeriod(),
            'git_commit' => $gitRepo !== null ? $this->readGitCommit($projectDir) : null,
            'deploy_admin_sso' => $stackConfig?->supportsAdminSso() ?? false,
            AppManager::DETAIL_APP_CONFIG_FITS => $appConfigFits,
        ]);
    }

    /**
     * The `checks/` directory of the recipe written for this repository, null when it ships none.
     */
    private function recipeChecksDirectory(?string $gitRepo): ?string
    {
        $dir = SourceRecipes::directoryFor($gitRepo);

        return $dir === null ? null : SourceRecipes::checksDirectory($dir);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function logDecision(array $decision, ?DeployLogger $logger): void
    {
        $sourceRecipe = $decision['source_recipe'] ?? null;
        $origin = $decision['source_recipe_origin'] ?? null;
        if (is_string($sourceRecipe) && is_string($origin)) {
            $logger?->info("Recipe named by {$sourceRecipe} ({$origin})");
        }
        $logger?->info("Detected project type: {$decision['label']}");
        $logger?->info("Using strategy: {$decision['strategy']}");
    }

    /**
     * Why a compose file the repository ships was not used ({@see ComposeFileInspector::ignoredReason()}),
     * so a wrong strategy is one log line rather than a silent hunt.
     *
     * @param  array<string, mixed>  $decision
     */
    private function explainIgnoredComposeFile(array $decision, string $projectDir, ?DeployLogger $logger): void
    {
        $composePath = ComposeFileInspector::firstIn($projectDir);
        if ($decision['strategy'] === Strategies::COMPOSE || $composePath === null) {
            return;
        }

        $why = ComposeFileInspector::ignoredReason($composePath);
        if ($why !== null) {
            $logger?->info(sprintf(
                'Compose file %s %s; using strategy %s instead',
                basename($composePath),
                $why,
                $decision['strategy']
            ));
        }
    }

    /**
     * `database:` is provisioned only by the writers that generate the app service.
     *
     * @param  array<string, mixed>  $decision
     */
    private function warnInertDatabase(array $decision, ?DeployLogger $logger): void
    {
        $inertDatabase = ManifestDatabase::inertWarning($decision);
        if ($inertDatabase !== null) {
            $logger?->warn($inertDatabase);
        }
    }

    /**
     * Detection that reached past the manifests still deploys, but nobody has written a recipe
     * for this project; reported, or the gap is only ever found by a support ticket.
     *
     * @param  array<string, mixed>  $decision
     */
    private function reportUnreciped(string $username, array $decision, string $projectDir): void
    {
        $unreciped = DetectionSignal::forDecision($decision, $projectDir);
        if ($unreciped !== null) {
            Telemetry::signal($username, $unreciped['signal'], $unreciped['detail']);
        }
    }

    /**
     * The manifest detection chose has the last word, before its own prepare commands run.
     *
     * @param  array<string, mixed>  $decision
     */
    private function deliverManifestCredentials(array $decision, ?AppConfig $appConfig): void
    {
        $this->dind->appCredentials()->deliver(
            CredentialSpec::fromArray(is_array($decision['credentials'] ?? null) ? $decision['credentials'] : null)
                ?? $appConfig?->credentials(),
            final: true
        );
    }

    /**
     * Apply the strategy, then env vars and proxy rules, the domain's then the declared public ports. The port scheme is kept
     * on the project for every later rule sync; ports are detected after `.env` exists, since compose publishes a host port taken from it.
     *
     * @param  array<string, mixed>  $decision
     */
    private function applyDecision(ModelsUser $user, array $decision, ?AppConfig $appConfig, string $projectDir, ?string $gitRepo): void
    {
        $chown = $user->getChownString();
        $sourceLabel = $gitRepo !== null ? GitUrl::sanitize($gitRepo) : 'uploaded archive';
        $this->dind->strategy()->apply($decision, $appConfig, $projectDir, $chown, $sourceLabel);
        $this->dind->strategy()->dropUndefinedOverrideServices($chown);
        $this->dind->strategy()->installRailsHostInitializer($projectDir, $chown);

        $user->setAppPortScheme($decision['port_scheme'] ?? $appConfig?->portScheme());
        $this->dind->applyProjectEnvVars(is_string($decision['compose_path'] ?? null) ? $decision['compose_path'] : null);
        $this->dind->networking()->detectAndCreateProxyRules($user);
        app(ProjectPublicRouting::class)->routePorts($user, $this->dind->shell()->logger());
        $this->dind->strategy()->keepEngineFilesOutOfBuildContext($decision, $projectDir, $chown);
    }

    /**
     * The {@see PlatformStage::PRECHECK} stage, stopping a deploy before it costs a clone: the app config is the
     * engine's, no `when` guard can be evaluated, and a refusal is marked so telemetry does not report a broken deploy.
     */
    public function preCheck(): void
    {
        $gitRepo = $this->dind->userModel()->getGitRepoOrFail();
        $appConfig = $this->dind->appConfig($gitRepo);
        $recipe = SourceRecipes::fromAppConfig(
            $appConfig,
            AppConfig::YAML_FILENAME,
            $this->recipeChecksDirectory($gitRepo)
        );
        $plan = app(DeployPlanContext::class)->get();
        $staged = $appConfig?->commands(PlatformStage::PRECHECK) ?? [];
        $extra = self::preCheckHookScript($appConfig);
        if (HostScript::isEmpty($recipe, PlatformStage::PRECHECK, null, $extra, $staged, $plan)) {
            return;
        }

        $path = $this->writePreCheckScript(
            HostScript::render($recipe, PlatformStage::PRECHECK, null, [], $extra, $staged, $plan)
        );
        try {
            $this->dind->shell()->execAsUserWithProjectEnv(['bash', '-c', self::RUN_WITH_STDOUT_ON_STDERR, $path]);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->dind->shell()->logger()?->markPreCheckRejected();
            throw $e;
        } finally {
            $this->dind->shell()->exec(['rm', '-f', $path]);
        }
    }

    /**
     * The app config's raw precheck hook, which runs after the staged commands: it has no
     * `before`, `optional`, `timeout` or `when` to keep.
     *
     * @return array<string, string>
     */
    private static function preCheckHookScript(?AppConfig $appConfig): array
    {
        $commands = $appConfig?->preCheckCommands();

        return is_string($commands) && $commands !== '' ? [AppConfig::PRE_CHECK_SCRIPT => $commands] : [];
    }

    private function writePreCheckScript(string $script): string
    {
        $path = $this->dind->homeDirPath().'/'.AppConfig::PRE_CHECK_SCRIPT;
        $chown = $this->dind->userModel()->getChownString();
        $this->dind->system()->filesystem()->writeFileReplacingPath($path, $script, $chown, '644');

        return $path;
    }

    private function assertGitHeadReadable(string $projectDir): void
    {
        try {
            $this->gitForProjectDir($projectDir)->assertHeadReadable();
        } catch (GitException $e) {
            throw new \InvalidArgumentException(
                'Git HEAD is not readable after clone; repository may be empty or corrupt.',
                0,
                $e,
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
     * Drop the welcome page once the account has an application of its own: files written one
     * at a time are never "loaded", and the page would compete with the user's index.
     */
    private function dropSupersededPlaceholder(string $projectDir, ?DeployLogger $logger): void
    {
        $index = PlaceholderPage::supersededIndex($projectDir);
        if ($index === null) {
            return;
        }

        $this->dind->system()->exec(Privileged::files()->removeIfExists($index));
        $logger?->info('Removed the placeholder page: the project has files of its own');
    }
}
