<?php

namespace App\Console\Commands\Usage;

use App\Http\Requests\VisitorsBreakdownRequest;
use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectUsage;

class DomainVisitorsBreakdownCommand extends UsageCommand
{
    protected $signature = 'project:domain:visitors-breakdown
                            {project : Project username}
                            {domain : Domain hostname}
                            {dimension : pages, countries, continents, regions, referrers, os, or browsers}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}';

    protected $description = 'Show a visitor breakdown for a domain (GET /projects/{username}/domains/{domain}/visitors/{dimension})';

    public function handle(ProjectUsage $usage, Statistics $statistics): int
    {
        return $this->answer(function () use ($usage, $statistics) {
            $input = $this->validated([
                'start' => $this->option('start'),
                'end' => $this->option('end'),
                'dimension' => $this->argument('dimension'),
            ], (new VisitorsBreakdownRequest())->rules());
            $domain = $this->domain($usage, $this->project());

            return $statistics->domainVisitorBreakdown($domain->domain, $input['dimension'], $input['start'], $input['end']);
        });
    }
}
