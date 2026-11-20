<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use App\Lib\Deploy\Inspect\InspectException;
use PHPUnit\Framework\TestCase;

/**
 * Drift says nothing about a field the inspection could not answer, and an inspection failure keeps the problem it carried.
 */
class SnapshotDriftGapsTest extends TestCase
{
    public function test_a_field_the_report_does_not_reach_is_not_drift(): void
    {
        $details = ['deploy_strategy' => 'php', 'deploy_runtime' => 'php', 'deploy_port' => 8000];
        $report = ['application' => 'not a section', 'ports' => ['primary' => 3000]];

        $this->assertSame(
            [['field' => 'port', 'deployed' => 8000, 'detected' => 3000]],
            DeploymentSnapshot::drift($details, $report)
        );
    }

    public function test_a_problem_carried_by_the_exception_is_the_problem_reported(): void
    {
        $problem = ['field' => 'git_repo', 'code' => 'redirect_not_followed', 'message' => 'The host redirected the clone.'];

        $this->assertSame($problem, InspectException::ofProblem($problem)->toProblem());
        $this->assertSame(
            ['field' => 'source', 'code' => 'source_unreadable', 'message' => 'gone'],
            (new InspectException('gone'))->toProblem()
        );
    }
}
