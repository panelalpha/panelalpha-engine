<?php

namespace App\Console\Commands\Usage;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use Illuminate\Console\Command;

class DomainVisitorsCommand extends Command
{
    use DispatchesApiRoute;

    protected $signature = 'project:domain:visitors
                            {project : Project username}
                            {domain : Domain hostname}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}';

    protected $description = 'Show visitor overview for a domain (GET /projects/{username}/domains/{domain}/visitors)';

    public function handle(): int
    {
        $project = rawurlencode((string) $this->argument('project'));
        $domain = rawurlencode((string) $this->argument('domain'));
        $response = $this->dispatchApiRoute('GET', "/projects/{$project}/domains/{$domain}/visitors", [
            'start' => $this->option('start'),
            'end' => $this->option('end'),
        ]);

        return $this->writeResponseBody($response, null);
    }
}
