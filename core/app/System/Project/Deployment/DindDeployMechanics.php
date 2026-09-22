<?php

namespace App\System\Project\Deployment;

use App\Lib\Domains\PublicUrl;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
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
        $this->stopApplicationBeforeWipe();
        $project->prepareLinuxIsolation();
        if ($zipPath !== null && $zipPath !== '') {
            $project->importProjectArchive($zipPath);
        }
        $project->recreateOuterCompose();
    }

    // prepareLinuxIsolation() clears ~/project and the re-clone lands on a new inode; any
    // container bind-mounted under the old one (n8n's ./docker, and every recipe shipping
    // overrides/) keeps reading the now-unlinked directory unless it's stopped first.
    private function stopApplicationBeforeWipe(): void
    {
        $app = $this->dind->app();
        if ($app === null) {
            return;
        }

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

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        if ($this->user()->hasGitProject() && ($zipPath === null || $zipPath === '')) {
            $this->dind->preCheckFromSources();
            (new GitRepository($this->dind))->cloneConfiguredRepository();
            // Re-clone lands on the same ~/project the wipe just cleared; bootstrap it
            // like ingestApplicationSource() does, or the app config's files never come back.
            $this->dind->prepareFromSources();
        } else {
            $this->dind->prepareFromSources();
        }
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->dind->prepareFromSources();
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->aggregate()->domain($domain)->create();
    }

    public function startApplication(): array
    {
        return $this->aggregate()->startUserApp();
    }

    public function abortPartialDeploy(): void
    {
        $this->aggregate()->abortRunningDeploy();
    }

    public function servingWarnings(): array
    {
        return AppHealth::servingWarnings($this->user()->getDetails());
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        return PublicUrl::warnings($domain, $this->user()->getDetails());
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
        ]);
        $this->user()->save();
    }

    public function persistSuccess(): void
    {
        $this->user()->setDetails([
            'deployment_status' => 'success',
        ]);
        $this->user()->save();
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
