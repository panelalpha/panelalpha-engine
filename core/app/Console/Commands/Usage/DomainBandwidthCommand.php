<?php

namespace App\Console\Commands\Usage;

use App\Http\Requests\BandwidthSeriesRequest;
use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectUsage;

class DomainBandwidthCommand extends UsageCommand
{
    protected $signature = 'project:domain:bandwidth
                            {project : Project username}
                            {domain : Domain hostname}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}
                            {--group-by=day : Bucket size (day or month)}';

    protected $description = 'Show bandwidth over a date range for a domain (GET /projects/{username}/domains/{domain}/bandwidth)';

    public function handle(ProjectUsage $usage, Statistics $statistics): int
    {
        return $this->answer(function () use ($usage, $statistics) {
            $range = $this->validated([
                'start' => $this->option('start'),
                'end' => $this->option('end'),
                'group_by' => $this->option('group-by'),
            ], (new BandwidthSeriesRequest())->rules());
            $domain = $this->domain($usage, $this->project());

            return $statistics->domainBandwidth($domain->domain, $range['start'], $range['end'], $range['group_by']);
        });
    }
}
