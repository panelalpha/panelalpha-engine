<?php

namespace App\System\Project\Backup;

use App\System\Project as UserProject;
use App\System\Project\Dind\ContainerOperations;

/** The account's inner compose stack; a start resumes only what a stop stopped. */
class AppStack
{
    private bool $stoppedServices = false;

    public function __construct(
        private UserProject $project,
    ) {}

    public function stop(): void
    {
        $this->stoppedServices = $this->containers()->composeStop() || $this->stoppedServices;
    }

    public function start(): void
    {
        if (! $this->stoppedServices) {
            return;
        }

        $this->containers()->composeStart();
        $this->stoppedServices = false;
    }

    private function containers(): ContainerOperations
    {
        $dind = DindRuntime::of($this->project);

        return new ContainerOperations($dind, $dind->shell());
    }
}
