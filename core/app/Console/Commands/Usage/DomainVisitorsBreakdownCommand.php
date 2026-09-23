<?php

namespace App\Console\Commands\Usage;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class DomainVisitorsBreakdownCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:domain:visitors-breakdown
                            {project : Project username}
                            {domain : Domain hostname}
                            {dimension : pages, countries, continents, regions, referrers, os, or browsers}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}';

    protected $description = 'Show a visitor breakdown for a domain (GET /projects/{username}/domains/{domain}/visitors/{dimension})';

    public function handle(): int
    {
        $project = rawurlencode((string) $this->argument('project'));
        $domain = rawurlencode((string) $this->argument('domain'));
        $dimension = rawurlencode((string) $this->argument('dimension'));
        $response = $this->dispatchApiRoute('GET', "/projects/{$project}/domains/{$domain}/visitors/{$dimension}", [
            'start' => $this->option('start'),
            'end' => $this->option('end'),
        ]);

        return $this->writeResponseBody($response, null);
    }
}
