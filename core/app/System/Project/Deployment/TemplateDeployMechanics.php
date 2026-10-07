<?php

namespace App\System\Project\Deployment;

use App\Lib\Domains\PublicUrl;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\AppHealth;
use RuntimeException;

/**
 * Deploy mechanics for a project on a plain compose template (wordpress,
 * static, ...) rather than DinD. Lifted out of UserController, which ran this
 * sequence inline and is why the pipeline existed twice.
 *
 * The source steps are no-ops here on purpose: a template project has no
 * repository to clone, and provision() forces `dind` on anything that does.
 */
final class TemplateDeployMechanics implements DeployMechanics
{
    public function __construct(private ProjectAggregate $project)
    {
    }

    public function user(): ModelsUser
    {
        return $this->project->model();
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
        $this->project->createDirectories();
        $osUser = $this->project->syncLinuxUser();
        $this->user()->setDetails([
            'UID' => $osUser['UID'],
            'GID' => $osUser['GID'],
        ]);
        $this->user()->save();
        $this->project->createFromTemplate();
        $this->project->up();
        $this->project->fixPermissions();
        $this->project->configureQuota();
        $this->project->waitForAllRunning();
    }

    public function ingestApplicationSource(): void
    {
        if ($this->user()->hasGitProject()) {
            $this->project->preCheckUserApp();
            $this->project->cloneUserApp();

            return;
        }

        $this->project->prepareUserAppFromSources();
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        return $this->project->isRunning();
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
        throw new RuntimeException('Checkout rebuild requires a DinD project.');
    }

    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        throw new RuntimeException('Source rebuild requires a DinD project.');
    }

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        throw new RuntimeException('Wipe rebuild requires a DinD project.');
    }

    public function reprepareApplicationFromCheckout(): void
    {
        throw new RuntimeException('Checkout rebuild requires a DinD project.');
    }

    public function ingestArchive(string $zipPath): void
    {
        throw new RuntimeException('Archive deploy requires a DinD project.');
    }

    public function publishDomain(DomainModel $domain): void
    {
        $domain->projectDomain()->create();
    }

    public function startApplication(): array
    {
        return $this->project->startUserApp();
    }

    public function abortPartialDeploy(bool $removeVolumes = false): void
    {
        $this->project->abortRunningDeploy(removeVolumes: $removeVolumes);
    }

    public function settleRedeploy(bool $succeeded): void
    {
    }

    public function servingWarnings(): array
    {
        return AppHealth::servingWarnings($this->user()->getDetails());
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        // The account's own domain column, as this path always read it.
        return PublicUrl::warnings($this->user()->domain, $this->user()->getDetails());
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
        // markDeploySucceeded() also clears deployment_warnings, so a clean
        // deploy does not inherit the last partial one's. DindDeployMechanics
        // does the same.
        $this->user()->markDeploySucceeded();
        $this->user()->save();
    }
}
