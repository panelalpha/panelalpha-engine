<?php

namespace App\Console\Commands\Usage;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class DomainBandwidthCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:domain:bandwidth
                            {project : Project username}
                            {domain : Domain hostname}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}
                            {--group-by=day : Bucket size (day or month)}';

    protected $description = 'Show bandwidth over a date range for a domain (GET /projects/{username}/domains/{domain}/bandwidth)';

    public function handle(): int
    {
        $project = rawurlencode((string) $this->argument('project'));
        $domain = rawurlencode((string) $this->argument('domain'));
        $response = $this->dispatchApiRoute('GET', "/projects/{$project}/domains/{$domain}/bandwidth", [
            'start' => $this->option('start'),
            'end' => $this->option('end'),
            'group_by' => $this->option('group-by'),
        ]);

        return $this->writeResponseBody($response, null);
    }
}
