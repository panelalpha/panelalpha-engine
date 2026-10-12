<?php

namespace App\System\Project\Deployment;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\PublicUrl;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
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
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DinD-from-source deploy mechanics on the App\System path.
 */
final class DindDeployMechanics implements DeployMechanics
{
    public function __construct(
        private Dind $dind,
    ) {
    }

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
        return $this->user()->getTemplate() === 'dind' && !$this->user()->hasGitProject();
    }

    public function prepareHostingEnvironment(): void
    {
        $project = $this->aggregate();
        if ($project->hostingExists()) {
            throw new \Exception('User already exists.');
        }

        $this->deleteHostingIfItFails($project, fn () => $project->createDirectories());
        $project->syncLinuxUser();
        $project->model()->save();
        $this->deleteHostingIfItFails($project, function () use ($project): void {
            $project->createFromTemplate();
            $project->up();
        });

        $project->fixPermissions();
        $project->configureQuota();
        $project->waitForAllRunning();
    }

    public function ingestApplicationSource(): void
    {
        if ($this->user()->hasGitProject()) {
            $this->dind->preCheckFromSources();
            (new GitRepository($this->dind))->cloneConfiguredRepository();
        }
        $this->dind->prepareFromSources();
        $this->excludeEngineArtifacts();
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
        if (!$this->reclonesOnRebuild($zipPath)) {
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
        $this->logger()?->info('Stopped the app first: ' . ($mounts === null ? 'its mounts could not be read' : implode(', ', $mounts)));

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
     * @param ?array{mounts: list<string>, containers: list<string>, running: list<string>} $read
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
     * @param array{exit_code: int, stdout: string, stderr: string} $result
     */
    private function warnAppNotStopped(array $result): void
    {
        Log::warning(
            "Could not stop the app before the wipe rebuild of {$this->user()->username}: "
            . trim($result['stderr'] ?: $result['stdout']),
        );
    }

    private function reclonesOnRebuild(?string $zipPath): bool
    {
        return $this->user()->hasGitProject() && !$this->hasArchive($zipPath);
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
        $this->dind->prepareFromSources();
        $this->excludeEngineArtifacts();
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->keepCheckoutReadable();
        $this->aggregate()->importProjectArchive($zipPath);
        $this->dind->prepareFromSources();
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->keepCheckoutReadable();
        $this->dind->prepareFromSources();
        $this->excludeEngineArtifacts();
    }

    /** The checkout a redeploy moved aside: back if it failed, gone once replaced. */
    public function settleRedeploy(bool $succeeded): void
    {
        GenerationSweep::settleIfIdle($this->dind, $succeeded);
    }

    /**
     * Its images and routing, before the redeploy prepares anything. Every redeploy passes here
     * first, so the sysbox account mapping is repaired here too.
     */
    public function recordRunningVersion(): void
    {
        (new AccountMapping($this->dind))->ensureMapped();
        (new ServingImages($this->dind))->remember();
        (new RoutingSnapshot($this->dind))->take();
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
                . CheckoutAside::DIR . ' before the change, and comes back if the new version fails'
            );

            return;
        }
        $read = (new ProjectBindMounts($this->dind))->read();
        if ($read !== null && $read['running'] !== []) {
            $this->keepCheckoutForRunningApp($read, copyBack: true);
        }
    }

    /**
     * @param array{mounts: list<string>, containers: list<string>, running: list<string>} $read
     */
    private function keepCheckoutForRunningApp(array $read, bool $copyBack): bool
    {
        $binds = $read['mounts'] !== [];
        if (!(new CheckoutAside($this->dind))->moveAside($read['running'], $copyBack, $binds)) {
            return false;
        }
        $this->logger()?->info($this->checkoutMovedAsideMessage($read['mounts']));

        return true;
    }

    /**
     * @param list<string> $mounts
     */
    private function checkoutMovedAsideMessage(array $mounts): string
    {
        if ($mounts === []) {
            return 'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
                . CheckoutAside::DIR . ', and comes back if the new version fails';
        }

        return 'Kept the running app up during the build: ' . implode(', ', $mounts)
            . ', so the checkout it reads moved to ~/' . CheckoutAside::DIR . ' until the new version replaces it';
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->aggregate()->domain($domain)->create();
    }

    public function startApplication(): array
    {
        return $this->aggregate()->startUserApp();
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
        if (!$this->user()->usedCustomEnvVars()) {
            return null;
        }

        return 'Deploy failed with custom environment variables — consider retrying without them to see whether they caused it.';
    }

    public function persistPartialSuccess(array $warnings): void
    {
        $this->user()->setDetails([
            'deployment_warnings' => $warnings,
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
    private function excludeEngineArtifacts(): void
    {
        EngineArtifactExclude::forProject($this->dind)->writeForDeploy();
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
