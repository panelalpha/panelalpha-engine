<?php

namespace App\Console\Commands\Usage;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class ProjectBandwidthCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:bandwidth
                            {project : Project username}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}
                            {--group-by=day : Bucket size (day or month)}';

    protected $description = 'Show bandwidth over a date range for a project (GET /projects/{username}/bandwidth)';

    public function handle(): int
    {
        $project = rawurlencode((string) $this->argument('project'));
        $response = $this->dispatchApiRoute('GET', "/projects/{$project}/bandwidth", [
            'start' => $this->option('start'),
            'end' => $this->option('end'),
            'group_by' => $this->option('group-by'),
        ]);

        return $this->writeResponseBody($response, null);
    }
}
