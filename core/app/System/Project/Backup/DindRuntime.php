<?php

namespace App\System\Project\Backup;

use App\System\Project as UserProject;
use App\System\Project\Dind;

final class DindRuntime
{
    public static function of(UserProject $project): Dind
    {
        $runtime = $project->runtime();
        if (! $runtime instanceof Dind) {
            throw new \RuntimeException('Backup requires a DinD project');
        }

        return $runtime;
    }
}
