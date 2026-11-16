<?php

namespace App\System\Project\Deployment;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Lib\Project\ProjectNameRefusal;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountMapping;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Generation\ServingImages;
use App\System\Project\Dind\ProjectBindMounts;
use App\System\Project\Dind\Source\EngineArtifactExclude;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Dind\SysboxDaemons;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DinD-from-source deploy mechanics on the App\System path.
 */
final class DindDeployMechanics implements DeployMechanics
{
    private bool $removedTheAccount = false;

    private ?RecipeFallback $recipeFallback = null;

    /** @var (\Closure(): void)|null how the last ingest prepared the checkout, for another recipe */
    private ?\Closure $prepareAgain = null;

    private ?RecipeAttempt $failedPrepare = null;

    public function __construct(
        private Dind $dind,
    ) {}

    public function user(): ModelsUser
    {
        return $this->dind->userModel();
    }

    public function requireMainDomain(): DomainModel
    {
        $domain = $this->user()->getMainDomain();
        if ($domain === null) {
            throw new RuntimeException("Project '{$this->user()->username}' has no main domain.");
        }

        return $domain;
    }

    public function hasGitProject(): bool
    {
        return $this->user()->hasGitProject();
    }

    public function isDindWithoutGit(): bool
    {
        return $this->user()->getTemplate() === 'dind' && ! $this->user()->hasGitProject();
    }

    /**
     * The account build cannot be stopped part way: a cancel asked for meanwhile is honoured
     * once it ends, and the new account is removed as after a failed build.
     */
    public function prepareHostingEnvironment(): void
    {
        $project = $this->aggregate();
        if ($project->hostingExists()) {
            throw new \Exception(ProjectNameRefusal::heldOnHost($project->model()->username, app(System::class)));
        }

        $this->refuseNewAccountWhileSysboxIsDown();
        $this->createAccount($project);
        $startedAt = microtime(true);
        $this->startAccountContainer($project);
        $this->handHomeToAccount($project);
        $project->waitForAllRunning();
        $this->reportAccountDaemon((int) round(microtime(true) - $startedAt));
        $this->deleteHostingIfItFails($project, fn () => $this->logger()?->throwIfCancelled());
    }

    /** Refused before anything is made, so the failed create has no account to keep. */
    private function refuseNewAccountWhileSysboxIsDown(): void
    {
        try {
            $this->sysbox()->refuseWhenStopped();
        } catch (RuntimeException $e) {
            $this->removedTheAccount = true;
            throw $e;
        }
    }

    private function createAccount(ProjectAggregate $project): void
    {
        $this->logger()?->info("Creating the account's directories and Linux user");
        $this->deleteHostingIfItFails($project, fn () => $project->createDirectories());
        $project->syncLinuxUser();
        $project->model()->save();
    }

    private function startAccountContainer(ProjectAggregate $project): void
    {
        $this->logger()?->info("Writing the account's compose file");
        $this->deleteHostingIfItFails($project, fn () => $project->createFromTemplate());
        $this->logger()?->info('Starting the account container and waiting for its Docker daemon');
        $this->deleteHostingIfItFails($project, fn () => $project->up());
    }

    private function handHomeToAccount(ProjectAggregate $project): void
    {
        $this->logger()?->info('Handing the home to the account and setting its disk quota');
        $project->fixPermissions();
        $project->configureQuota();
    }

    /** The wait gives up silently, so a daemon that never answered would otherwise surface only as a later docker error. */
    private function reportAccountDaemon(int $seconds): void
    {
        if ($this->dind->innerDockerIsUp()) {
            $this->logger()?->ok("The account's Docker daemon answered {$seconds}s after the container start");

            return;
        }
        $this->logger()?->warn(
            "The account's Docker daemon did not answer within {$seconds}s of the container start;"
                .' the deploy goes on, and its next docker command reports why'
        );
    }

    public function removedTheAccount(): bool
    {
        return $this->removedTheAccount;
    }

    public function ingestApplicationSource(): void
    {
        if ($this->user()->hasGitProject()) {
            $this->dind->preCheckFromSources();
            (new GitRepository($this->dind))->cloneConfiguredRepository();
        }
        $this->prepareApplication();
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        return $this->user()->getTemplate() === 'dind' && $this->aggregate()->isRunning();
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
        $project = $this->aggregate();
        if ($reuseRunning) {
            $project->syncLinuxUser();

            return;
        }

        $project->createHomeDir();
        $project->createProjectDir();
        $project->syncLinuxUser();
        $project->configureQuota();
        $project->createFromTemplate();
        $project->buildIfMissing();
        $project->down();
        $project->up();
    }

    /** Wipes ~/project for a rebuild; a git re-clone stops the app itself, once the new source is in hand. */
    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        $project = $this->aggregate();
        if (! $this->reclonesOnRebuild($zipPath)) {
            $this->stopApplicationBeforeWipe(copyBack: true);
        }
        $this->logger()?->info('Preparing the account directories and Linux user');
        $project->prepareLinuxIsolation();
        if ($this->hasArchive($zipPath)) {
            $project->importProjectArchive($zipPath);
        }
        $this->logger()?->info("Recreating the account's outer compose file");
        $project->recreateOuterCompose();
    }

    /**
     * Stops an app whose containers bind-mount ~/project, which the wipe replaces with a new inode,
     * unless the checkout can be moved aside; $copyBack when the deploy builds on the files already there.
     */
    private function stopApplicationBeforeWipe(bool $copyBack = false): void
    {
        $app = $this->dind->app();
        if ($app === null) {
            return;
        }

        $read = (new ProjectBindMounts($this->dind))->read();
        if ($this->keepsServingThroughWipe($read, $copyBack)) {
            return;
        }
        $mounts = $read['mounts'] ?? null;
        $this->logger()?->info('Stopped the app first: '.($mounts === null ? 'its mounts could not be read' : implode(', ', $mounts)));

        $wasRunning = $this->aggregate()->isRunning();
        $result = $app->projectAction('down');
        if ($result['exit_code'] !== 0 && $wasRunning) {
            $this->warnAppNotStopped($result);
        }
    }

    /**
     * An app that mounts nothing under ~/project keeps serving (`up -d` recreates it last),
     * and so does one whose checkout could be moved aside.
     *
     * @param  ?array{mounts: list<string>, containers: list<string>, running: list<string>}  $read
     */
    private function keepsServingThroughWipe(?array $read, bool $copyBack): bool
    {
        if ($read !== null && $read['running'] !== [] && $this->keepCheckoutForRunningApp($read, $copyBack)) {
            return true;
        }
        if (($read['mounts'] ?? null) !== []) {
            return false;
        }
        $this->logger()?->info('Kept the running app up during the build');

        return true;
    }

    /**
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function warnAppNotStopped(array $result): void
    {
        Log::warning(
            "Could not stop the app before the wipe rebuild of {$this->user()->username}: "
            .trim($result['stderr'] ?: $result['stdout']),
        );
    }

    private function reclonesOnRebuild(?string $zipPath): bool
    {
        return $this->user()->hasGitProject() && ! $this->hasArchive($zipPath);
    }

    private function hasArchive(?string $zipPath): bool
    {
        return $zipPath !== null && $zipPath !== '';
    }

    /**
     * Re-clones beside ~/project, so a failed clone keeps the app up, then bootstraps the checkout and
     * rewrites the Engine Artifact exclude a fresh clone lacks, or a push's `clean -fd` removes them (ADR-0001).
     */
    public function ingestForWipeRebuild(?string $zipPath): void
    {
        if ($this->reclonesOnRebuild($zipPath)) {
            $this->dind->preCheckFromSources();
            (new GitRepository($this->dind))->cloneConfiguredRepository(
                fn () => $this->stopApplicationBeforeWipe(),
            );
        }
        $this->prepareApplication();
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->keepCheckoutReadable();
        $this->aggregate()->importProjectArchive($zipPath);
        $this->prepareApplication(excludeArtifacts: false);
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->keepCheckoutReadable();
        $this->fetchSubmodulesOfCheckout();
        $this->prepareApplication();
    }

    /** The checkout a redeploy moved aside: back if it failed, gone once replaced. */
    public function settleRedeploy(bool $succeeded): void
    {
        GenerationSweep::settleIfIdle($this->dind, $succeeded);
    }

    /**
     * Its images and routing, before the redeploy prepares anything. Every redeploy passes here
     * first, so a stopped sysbox refuses it here and the sysbox account mapping is repaired here too.
     */
    public function recordRunningVersion(): void
    {
        $this->sysbox()->refuseWhenStopped();
        $this->repairAccountMapping();
        (new ServingImages($this->dind))->remember();
        (new RoutingSnapshot($this->dind))->take();
    }

    public function repairAccountMapping(): void
    {
        (new AccountMapping($this->dind))->ensureMapped();
    }

    /** A pull, a branch change or a push moves the checkout, and with it the submodule commits it records. */
    private function fetchSubmodulesOfCheckout(): void
    {
        $user = $this->user();
        if (! $user->hasGitProject()) {
            return;
        }
        (new GitRepository($this->dind))->fetchSubmodules((string) $user->getGitRepo(), $user->getGitToken());
    }

    /** A pull or an archive builds on a copy; the running app keeps the tree it was deployed from. */
    private function keepCheckoutReadable(): void
    {
        if ($this->dind->app() === null) {
            return;
        }
        if ((new CheckoutAside($this->dind))->keptByThisProcess()) {
            $this->logger()?->info(
                'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
                .CheckoutAside::DIR.' before the change, and comes back if the new version fails'
            );

            return;
        }
        $read = (new ProjectBindMounts($this->dind))->read();
        if ($read !== null && $read['running'] !== []) {
            $this->keepCheckoutForRunningApp($read, copyBack: true);
        }
    }

    /**
     * @param  array{mounts: list<string>, containers: list<string>, running: list<string>}  $read
     */
    private function keepCheckoutForRunningApp(array $read, bool $copyBack): bool
    {
        $binds = $read['mounts'] !== [];
        if (! (new CheckoutAside($this->dind))->moveAside($read['running'], $copyBack, $binds)) {
            return false;
        }
        $this->logger()?->info($this->checkoutMovedAsideMessage($read['mounts']));

        return true;
    }

    /**
     * @param  list<string>  $mounts
     */
    private function checkoutMovedAsideMessage(array $mounts): string
    {
        if ($mounts === []) {
            return 'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
                .CheckoutAside::DIR.', and comes back if the new version fails';
        }

        return 'Kept the running app up during the build: '.implode(', ', $mounts)
            .', so the checkout it reads moved to ~/'.CheckoutAside::DIR.' until the new version replaces it';
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->aggregate()->domain($domain)->create();
    }

    /** Every caller has just prepared the checkout, so a failed prepare or start may prepare it for another recipe. */
    public function startApplication(): array
    {
        $failedPrepare = $this->failedPrepare;
        $this->failedPrepare = null;

        return $this->recipeFallback()->start(
            fn (): array => $this->aggregate()->startUserApp(),
            $this->prepareAgain ?? $this->preparing(true),
            $failedPrepare
        );
    }

    public function abortPartialDeploy(bool $removeVolumes = false): void
    {
        $this->aggregate()->abortRunningDeploy(removeVolumes: $removeVolumes);
    }

    public function servingWarnings(): array
    {
        return AppHealth::servingWarnings($this->user()->getDetails());
    }

    /** Passes the domain name, never the model: a model cast to string is its whole JSON, env_vars included. */
    public function publicUrlWarnings(DomainModel $domain): array
    {
        return PublicUrl::warnings((string) $domain->domain, $this->user()->getDetails());
    }

    public function customEnvFailureHint(): ?string
    {
        if (! $this->user()->usedCustomEnvVars()) {
            return null;
        }

        return 'Deploy failed with custom environment variables — consider retrying without them to see whether they caused it.';
    }

    public function persistPartialSuccess(array $warnings): void
    {
        $this->user()->setDetails([
            'deployment_warnings' => Finding::storable($warnings),
            'deployment_status' => 'partial',
            'error' => null,
            'app_stopped_by_request' => null,
        ]);
        $this->user()->save();
    }

    public function persistSuccess(): void
    {
        $this->user()->markDeploySucceeded();
        $this->user()->save();
    }

    /**
     * List what the deploy just wrote in the checkout's local exclude file
     * (ADR-0001), once the app is prepared and every Engine Artifact exists.
     */
    /**
     * A failure another recipe may not repeat waits for startApplication() to fall back from it ({@see RecipeFallback});
     * any other is the deploy's, as before.
     *
     * @throws \Exception
     */
    private function prepareApplication(bool $excludeArtifacts = true): void
    {
        $this->prepareAgain = $this->preparing($excludeArtifacts);
        $attempt = RecipeAttempt::run(function (): array {
            ($this->prepareAgain)();

            return ['stdout' => '', 'stderr' => '', 'exit_code' => 0];
        });
        if ($attempt->failed() && ! $this->recipeFallback()->defers($attempt)) {
            $attempt->result();
        }
        $this->failedPrepare = $attempt->failed() ? $attempt : null;
    }

    /** @return \Closure(): void */
    private function preparing(bool $excludeArtifacts): \Closure
    {
        return function () use ($excludeArtifacts): void {
            $this->dind->prepareFromSources();
            if ($excludeArtifacts) {
                $this->excludeEngineArtifacts();
            }
        };
    }

    private function recipeFallback(): RecipeFallback
    {
        return $this->recipeFallback ??= new RecipeFallback($this->dind);
    }

    private function excludeEngineArtifacts(): void
    {
        EngineArtifactExclude::forProject($this->dind)->writeForDeploy();
    }

    private function sysbox(): SysboxDaemons
    {
        return new SysboxDaemons($this->dind->system());
    }

    private function aggregate(): ProjectAggregate
    {
        return $this->dind->project();
    }

    private function logger(): ?DeployLogger
    {
        return $this->dind->shell()->logger();
    }

    /** Runs a provisioning step and deletes the half-made hosting when it throws. */
    private function deleteHostingIfItFails(ProjectAggregate $project, callable $step): void
    {
        try {
            $step();
        } catch (\Exception $e) {
            $this->deleteHostingQuietly($project);
            $this->removedTheAccount = true;
            throw $e;
        }
    }

    private function deleteHostingQuietly(ProjectAggregate $project): void
    {
        try {
            $project->delete();
        } catch (\Exception) {
        }
    }
}
