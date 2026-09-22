<?php

namespace App\Console\Commands\Usage;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class ProjectUsageCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:usage {project : Project username}';

    protected $description = 'Show resource usage for a project (GET /projects/{username}/usage)';

    public function handle(): int
    {
        $project = rawurlencode((string) $this->argument('project'));
        $response = $this->dispatchApiRoute('GET', "/projects/{$project}/usage");

        return $this->writeResponseBody($response, null);
    }
}
