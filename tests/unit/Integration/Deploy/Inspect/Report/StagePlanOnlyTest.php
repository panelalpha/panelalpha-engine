<?php

namespace Tests\Integration\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\StageSchedule;
use App\Lib\Deploy\Platform\DeployPlan;

/**
 * A project no manifest claims still shows the stages a deploy plan spoke for, as the request wrote them.
 */
class StagePlanOnlyTest extends ReportTestCase
{
    public function test_the_plans_stages_are_the_whole_schedule_of_an_unclaimed_project(): void
    {
        $this->write('main.go', 'package main');
        $plan = DeployPlan::fromArray([
            'upgrade' => [['id' => 'migrate', 'run' => './app migrate']],
            'start' => [],
        ]);

        $stages = StageSchedule::of($this->context(), [], null, $plan);

        $this->assertSame(['migrate' => './app migrate'], array_column($stages['upgrade'], 'run', 'id'));
        $this->assertSame('request', $stages['upgrade'][0]['source']);
        $this->assertSame([], $stages['start']);
    }
}
