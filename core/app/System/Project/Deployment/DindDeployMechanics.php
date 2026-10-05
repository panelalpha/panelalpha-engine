<?php

namespace App\System\Project\Deployment;

use App\Lib\Domains\PublicUrl;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
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
 * DinD-from-source deploy mechanics on the App\System path (replaces Lib User forwarding at cutover prep).
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

        try {
            $project->createDirectories();
        } catch (\Exception $e) {
            $this->deleteHostingOnFailure($project);
            throw $e;
        }

        $project->syncLinuxUser();
        $project->model()->save();

        try {
            $project->createFromTemplate();
            $project->up();
        } catch (\Exception $e) {
            $this->deleteHostingOnFailure($project);
            throw $e;
        }

        $project->fixPermissions();
        $project->configureQuota();
        $project->waitForAllRunning();
    }

    public function ingestApplicationSource(): void
    {
        if ($this->user()->hasGitProject()) {
            $this->dind->preCheckFromSources();
            (new GitRepository($this->dind))->cloneConfiguredRepository();
            $this->dind->prepareFromSources();
        } else {
            $this->dind->prepareFromSources();
        }
        $this->excludeEngineArtifacts();
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        return $this->user()->getTemplate() === 'dind' && $this->aggregate()->isRunning();
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
        $project = $this->aggregate();
        if (!$reuseRunning) {
            $project->createHomeDir();
            $project->createProjectDir();
            $project->syncLinuxUser();
            $project->configureQuota();
            $project->createFromTemplate();
            $project->buildIfMissing();
            $project->down();
            $project->up();
        } else {
            $project->syncLinuxUser();
        }
    }

    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        $project = $this->aggregate();
        $this->noteTheRunningVersion();
        // A git re-clone stops the app itself, once the new source is in hand.
        if (!$this->reclonesOnRebuild($zipPath)) {
            $this->stopApplicationBeforeWipe(copyBack: true);
        }
        $project->prepareLinuxIsolation();
        if ($zipPath !== null && $zipPath !== '') {
            $project->importProjectArchive($zipPath);
        }
        $project->recreateOuterCompose();
    }

    // prepareLinuxIsolation() clears ~/project and the re-clone lands on a new inode; any
    // container bind-mounted under the old one (n8n's ./docker, and every recipe shipping
    // overrides/) keeps reading the now-unlinked directory unless it's stopped first.
    // Any other app keeps serving through the build: `up -d` recreates it last. One that
    // mounts the checkout keeps serving too when the old tree can be moved aside instead
    // of wiped; $copyBack when the deploy builds on the files already there.
    private function stopApplicationBeforeWipe(bool $copyBack = false): void
    {
        $app = $this->dind->app();
        if ($app === null) {
            return;
        }

        $logger = $this->dind->shell()->logger();
        $read = (new ProjectBindMounts($this->dind))->read();
        $mounts = $read['mounts'] ?? null;
        if ($read !== null && $read['running'] !== [] && $this->keepCheckoutForRunningApp($read, $copyBack)) {
            return;
        }
        if ($mounts === []) {
            $logger?->info('Kept the running app up during the build');

            return;
        }
        $logger?->info('Stopped the app first: ' . ($mounts === null ? 'its mounts could not be read' : implode(', ', $mounts)));

        $warn = $this->aggregate()->isRunning();

        $result = $app->projectAction('down');
        if ($result['exit_code'] === 0 || !$warn) {
            return;
        }

        Log::warning(
            "Could not stop the app before the wipe rebuild of {$this->user()->username}: "
            . trim($result['stderr'] ?: $result['stdout']),
        );
    }

    private function reclonesOnRebuild(?string $zipPath): bool
    {
        return $this->user()->hasGitProject() && ($zipPath === null || $zipPath === '');
    }

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        if ($this->reclonesOnRebuild($zipPath)) {
            $this->dind->preCheckFromSources();
            // Cloned beside ~/project first: a failed clone must not take the running app down.
            (new GitRepository($this->dind))->cloneConfiguredRepository(
                fn () => $this->stopApplicationBeforeWipe(),
            );
            // Re-clone lands on the same ~/project the wipe just cleared; bootstrap it
            // like ingestApplicationSource() does, or the app config's files never come back.
            $this->dind->prepareFromSources();
            // The fresh clone has a fresh .git/info/exclude, and nothing after
            // this step writes the block -- without it every Engine Artifact
            // shows up in `git status` (ADR-0001).
            $this->excludeEngineArtifacts();
        } else {
            $this->dind->prepareFromSources();
        }
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->noteTheRunningVersion();
        $this->keepCheckoutReadable();
        $this->aggregate()->importProjectArchive($zipPath);
        $this->dind->prepareFromSources();
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->noteTheRunningVersion();
        $this->keepCheckoutReadable();
        $this->dind->prepareFromSources();
        $this->excludeEngineArtifacts();
    }

    /** The checkout a redeploy moved aside: back if it failed, gone once replaced. */
    public function settleRedeploy(bool $succeeded): void
    {
        GenerationSweep::settleIfIdle($this->dind, $succeeded);
    }

    /** Its images and routing, before the redeploy prepares anything. */
    private function noteTheRunningVersion(): void
    {
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
            $this->dind->shell()->logger()?->info(
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
        $this->dind->shell()->logger()?->info($binds
            ? 'Kept the running app up during the build: ' . implode(', ', $read['mounts'])
                . ', so the checkout it reads moved to ~/' . CheckoutAside::DIR . ' until the new version replaces it'
            : 'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
                . CheckoutAside::DIR . ', and comes back if the new version fails');

        return true;
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

    public function publicUrlWarnings(DomainModel $domain): array
    {
        // The name, not the model: `warnings()` takes a string, and a model
        // coerced into one is its whole JSON -- which is how every partial
        // deploy's log line came to carry the domain, the account details and
        // the encrypted env_vars blob inside the sentence.
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
        if ($this->hasGitProject()) {
            EngineArtifactExclude::forProject($this->dind)->write();
        }
    }

    private function aggregate(): ProjectAggregate
    {
        return $this->dind->project();
    }

    private function deleteHostingOnFailure(ProjectAggregate $project): void
    {
        try {
            $project->delete();
        } catch (\Exception) {
        }
    }
}
