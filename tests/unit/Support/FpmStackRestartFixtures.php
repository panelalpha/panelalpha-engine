<?php

namespace Tests\Support;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;

trait FpmStackRestartFixtures
{
    /** The restart an account rendered before s6 gets. */
    private function runnerScript(string $phpVersion): string
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $project = new PhpHosting(new ProjectAggregate(new System(), $model));

        return FpmStack::restartScript(new RunnerServiceManager($project), $phpVersion);
    }
}
