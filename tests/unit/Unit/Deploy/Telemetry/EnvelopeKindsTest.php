<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * The fields a bug report and a health finding add to the shared envelope, which a deploy's never carry.
 */
class EnvelopeKindsTest extends TestCase
{
    public function test_a_bug_report_is_its_own_family_with_its_severity_and_area(): void
    {
        $event = Telemetry::envelope([
            'kind' => BugReport::KIND,
            'outcome' => 'reported',
            'occurred_at' => 1_700_000_000,
            'bug' => ['severity' => 'high', 'area' => 'deploy'],
        ]);

        $this->assertSame(Telemetry::EVENT_BUG_REPORT, $event['type']);
        $this->assertSame('bug_report', $event['payload']['software_op']);
        $this->assertSame('reported', $event['payload']['status']);
        $this->assertSame('high', $event['payload']['severity']);
        $this->assertSame('deploy', $event['payload']['area']);
        $this->assertArrayNotHasKey('success', $event['payload']);
    }

    public function test_a_bug_report_without_its_bug_block_names_no_severity(): void
    {
        $event = Telemetry::envelope(['kind' => BugReport::KIND, 'outcome' => 'reported', 'bug' => 'garbled']);

        $this->assertNull($event['payload']['severity']);
        $this->assertNull($event['payload']['area']);
    }

    public function test_a_health_finding_binds_to_the_worst_failing_severity(): void
    {
        $event = Telemetry::envelope([
            'kind' => Telemetry::KIND_HEALTH,
            'outcome' => 'partial',
            'occurred_at' => 1_700_000_000,
            'health' => ['serving' => 'placeholder', 'failed' => [
                ['severity' => 'info'], ['severity' => 'warning'], 'junk', ['severity' => 'error'], ['severity' => 'info'],
            ]],
        ]);

        $this->assertSame(Telemetry::EVENT_HEALTH, $event['type']);
        $this->assertSame('health_check', $event['payload']['software_op']);
        $this->assertSame('placeholder', $event['payload']['serving']);
        $this->assertSame('error', $event['payload']['severity']);
        $this->assertArrayNotHasKey('success', $event['payload']);
    }

    public function test_a_warning_outranks_an_info_whichever_comes_first(): void
    {
        $severity = static fn (mixed $failed): ?string => Telemetry::envelope([
            'kind' => Telemetry::KIND_HEALTH,
            'outcome' => 'partial',
            'health' => ['failed' => $failed],
        ])['payload']['severity'];

        $this->assertSame('warning', $severity([['severity' => 'warning'], ['severity' => 'info']]));
        $this->assertSame('warning', $severity([['severity' => 'info'], ['severity' => 'warning']]));
        $this->assertSame('info', $severity([['severity' => 'info'], ['severity' => 'unknown']]));
        $this->assertNull($severity([]));
        $this->assertNull($severity('not a list'));
    }

    public function test_a_health_finding_without_its_health_block_serves_nothing(): void
    {
        $event = Telemetry::envelope(['kind' => Telemetry::KIND_HEALTH, 'outcome' => 'partial']);

        $this->assertNull($event['payload']['serving']);
        $this->assertNull($event['payload']['severity']);
    }

    public function test_the_kind_decides_the_type_whatever_the_outcome(): void
    {
        $this->assertSame(Telemetry::EVENT_BUG_REPORT, Telemetry::eventType('failed', BugReport::KIND));
        $this->assertSame(Telemetry::EVENT_HEALTH, Telemetry::eventType('success', Telemetry::KIND_HEALTH));
        $this->assertSame(Telemetry::EVENT_FAIL, Telemetry::envelope(['kind' => '', 'outcome' => 'failed'])['type']);
    }
}
