<?php

namespace App\System\Project\Backup;

use App\System\Project as UserProject;
use App\System\Project\Dind\ContainerOperations;

/** The account's inner compose stack. */
class AppStack
{
    public function __construct(
        private UserProject $project,
    ) {}

    public function stop(): void
    {
        $this->containers()->composeStop();
    }

    public function start(): void
    {
        $this->containers()->composeStart();
    }

    private function containers(): ContainerOperations
    {
        $dind = DindRuntime::of($this->project);

        return new ContainerOperations($dind, $dind->shell());
    }
}
