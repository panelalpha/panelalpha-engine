<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\CheckoutRedeploy;
use App\System\Project\Dind\Source\Files;
use App\System\Project\Dind\Source\GitRepository;

/**
 * Source transport for a DinD Application (git checkout and archive ingest).
 * Deploy preparation and strategy application stay on Project\Deployment.
 */
final class SourceAccess
{
    private ?GitRepository $git = null;

    private ?Files $files = null;

    public function __construct(
        private DindProject $project,
    ) {}

    public function git(): GitRepository
    {
        return $this->git ??= new GitRepository($this->project);
    }

    public function files(): Files
    {
        return $this->files ??= new Files($this->project);
    }

    /**
     * Rebuild the running app after pull/revert/change-branch on deploy-managed git checkouts.
     */
    public function afterGitMutation(GitRepository $git): void
    {
        (new CheckoutRedeploy)->afterMutation($git, $this->project);
    }
}
