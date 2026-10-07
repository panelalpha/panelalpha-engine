<?php

namespace App\Console\Commands\Usage;

use App\Lib\Usage\ProjectUsage;

class ProjectUsageCommand extends UsageCommand
{
    protected $signature = 'project:usage {project : Project username}';

    protected $description = 'Show resource usage for a project';

    public function handle(ProjectUsage $usage): int
    {
        return $this->printJson($usage->summary($this->project()));
    }
}
