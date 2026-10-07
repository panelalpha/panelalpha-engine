<?php

namespace App\Console\Commands\Usage;

use App\Http\Requests\VisitorsRangeRequest;
use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectUsage;

class DomainVisitorsCommand extends UsageCommand
{
    protected $signature = 'project:domain:visitors
                            {project : Project username}
                            {domain : Domain hostname}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}';

    protected $description = 'Show visitor overview for a domain';

    public function handle(ProjectUsage $usage, Statistics $statistics): int
    {
        $range = $this->validated([
            'start' => $this->option('start'),
            'end' => $this->option('end'),
        ], (new VisitorsRangeRequest())->rules());
        $domain = $this->domain($usage, $this->project());

        return $this->printJson($statistics->domainVisitors($domain->domain, $range['start'], $range['end']));
    }
}
