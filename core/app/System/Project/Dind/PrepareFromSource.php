<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Git\Exception as GitException;
use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Credentials\CredentialSpec;
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
use App\System\Project\Dind\Strategy\ManifestDatabase;
use App\Models\User as ModelsUser;

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
     * Shared by git clone and zip import, so an upload gets the same treatment as a clone.
     * Credentials are delivered before the app config's prepare hook, which may read them,
     * and finally from the manifest detection chose.
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
        $this->dind->appCredentials()->deliver($appConfig?->credentials(), final: false);
        $this->dind->strategy()->bootstrap($appConfig, $projectDir, $chown);

        $this->dropSupersededPlaceholder($projectDir, $logger);

        $decision = $this->detectDecision($projectDir, $gitRepo, $logger);
        $this->assertDeployable($decision, $projectDir, $gitRepo, $logger);
        $this->freezeSnapshot($decision, $appConfig, $projectDir, $gitRepo);
        $this->logDecision($decision, $logger);
        $this->explainIgnoredComposeFile($decision, $projectDir, $logger);
        $this->warnInertDatabase($decision, $logger);
        $this->reportUnreciped($user->username, $decision, $projectDir);
        $this->deliverManifestCredentials($decision, $appConfig);

        $this->applyDecision($user, $decision, $appConfig, $projectDir, $gitRepo);
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
                'Deploy request replaced these stages: ' . implode(', ', $plan->stages())
            );
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $decision
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
     * Record what was detected. `deploy_platform` is the manifest that claimed it (`html` and
     * `static` share a strategy); the recipe's checks dir and the resolved image are frozen, not re-derived.
     *
     * @param array<string, mixed> $decision
     */
    private function freezeSnapshot(array $decision, ?AppConfig $appConfig, string $projectDir, ?string $gitRepo): void
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
            AppHealth::DETAIL_START_PERIOD => $appConfig?->startPeriod(),
            'git_commit' => $gitRepo !== null ? $this->readGitCommit($projectDir) : null,
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
     * @param array<string, mixed> $decision
     */
    private function logDecision(array $decision, ?DeployLogger $logger): void
    {
        $sourceRecipe = $decision['source_recipe'] ?? null;
        if (is_string($sourceRecipe)) {
            $logger?->info("Recipe named by {$sourceRecipe}");
        }
        $logger?->info("Detected project type: {$decision['label']}");
        $logger?->info("Using strategy: {$decision['strategy']}");
    }

    /**
     * Why a compose file the repository ships was not used (a workstation dev compose, or the
     * v1 layout), so a wrong strategy is one log line rather than a silent hunt.
     *
     * @param array<string, mixed> $decision
     */
    private function explainIgnoredComposeFile(array $decision, string $projectDir, ?DeployLogger $logger): void
    {
        if ($decision['strategy'] === Strategies::COMPOSE) {
            return;
        }

        if (($composePath = ComposeFileInspector::firstIn($projectDir)) !== null
            && ($reason = ComposeFileInspector::localDevComposeReason($composePath)) !== null
        ) {
            $logger?->info(sprintf(
                'Compose file %s looks like a workstation dev compose (%s); using strategy %s instead',
                basename($composePath),
                $reason,
                $decision['strategy']
            ));
        }
        if (($composePath = ComposeFileInspector::firstIn($projectDir)) !== null
            && ComposeFileInspector::isLegacyV1Compose($composePath)
        ) {
            $logger?->info(sprintf(
                'Compose file %s uses the v1 layout (no `services:`), which Docker Compose v2 refuses; using strategy %s instead',
                basename($composePath),
                $decision['strategy']
            ));
        }
    }

    /**
     * `database:` is provisioned only by the writers that generate the app service.
     *
     * @param array<string, mixed> $decision
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
     * @param array<string, mixed> $decision
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
     * @param array<string, mixed> $decision
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
     * Apply the strategy, then the project's env vars and proxy rules. The port scheme is kept on
     * the project for every later rule sync; ports are detected after `.env` exists, since compose
     * publishes a host port taken from it.
     *
     * @param array<string, mixed> $decision
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
        $this->dind->strategy()->keepEngineFilesOutOfBuildContext($decision, $projectDir, $chown);
    }

    /**
     * Run the precheck stage: validation that should stop a deploy before it costs a clone.
     * Nothing is cloned yet, so the app config is the engine's, no `when` guard can be evaluated,
     * and a refusal is marked so telemetry does not report it as a broken deploy.
     * {@see PlatformStage::PRECHECK}
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
        $path = $this->dind->homeDirPath() . '/' . AppConfig::PRE_CHECK_SCRIPT;
        $chown = $this->dind->userModel()->getChownString();
        $this->dind->system()->filesystem()->filePutContents($path, $script, $chown, '644');

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

        $this->dind->system()->exec(['sudo', 'rm', '-f', $index]);
        $logger?->info('Removed the placeholder page: the project has files of its own');
    }
}
