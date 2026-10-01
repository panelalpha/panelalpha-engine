<?php

namespace App\Console\Commands\Usage;

use App\Http\Requests\BandwidthSeriesRequest;
use App\Lib\Usage\ProjectUsage;

class ProjectBandwidthCommand extends UsageCommand
{
    protected $signature = 'project:bandwidth
                            {project : Project username}
                            {--start= : Range start (Y-m-d)}
                            {--end= : Range end (Y-m-d)}
                            {--group-by=day : Bucket size (day or month)}';

    protected $description = 'Show bandwidth over a date range for a project';

    public function handle(ProjectUsage $usage): int
    {
        $range = $this->validated([
            'start' => $this->option('start'),
            'end' => $this->option('end'),
            'group_by' => $this->option('group-by'),
        ], (new BandwidthSeriesRequest())->rules());
        $user = $this->project();

        return $this->printJson($usage->projectBandwidth($user, $range['start'], $range['end'], $range['group_by']));
    }
}
