<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The local telemetry log is the half that is not allowed to be empty.
 *
 * Remote delivery is optional, gated and best-effort. The file on the box is
 * what an operator tails while an install is going wrong, and what a customer
 * sends us weeks later -- so nothing, including telemetry being switched off,
 * may stop a deploy being written to it.
 */
class LocalRecordTest extends TestCase
{
    public function test_the_telemetry_channel_is_configured_and_resolvable(): void
    {
        $config = config('logging.channels.telemetry');

        $this->assertIsArray($config, 'logging.channels.telemetry is missing');
        $this->assertSame(storage_path('logs/telemetry.log'), $config['path']);

        // The engine replaces Laravel's `daily` with a handler that recovers
        // from the permission failure a root-run deploy provokes. A telemetry
        // channel on the stock driver would throw exactly when a deploy fails.
        $this->assertSame('custom', $config['driver']);
        $this->assertSame(\App\Logging\CustomDailyLogger::class, $config['via']);

        // Resolving it proves the wiring, not just the array.
        $this->assertNotNull(Log::channel('telemetry'));
    }

    public function test_envelope_marks_only_a_failure_as_unsuccessful(): void
    {
        foreach (
            [
                DeployReport::OUTCOME_FAILED => false,
                DeployReport::OUTCOME_SUCCESS => true,
                DeployReport::OUTCOME_PARTIAL => true,
                DeployReport::OUTCOME_RECOVERED => true,
                DeployReport::OUTCOME_CANCELLED => true,
            ] as $outcome => $expected
        ) {
            $event = Telemetry::envelope(['outcome' => $outcome, 'occurred_at' => 1_700_000_000]);

            $this->assertSame(
                $expected,
                $event['payload']['success'],
                "outcome '{$outcome}' mapped to the wrong success flag"
            );
            $this->assertSame($outcome, $event['payload']['status']);
        }
    }

    public function test_an_outcome_this_engine_cannot_name_reports_the_bare_family(): void
    {
        // An older ingest learning "an install happened, from an engine newer
        // than me" is true. Forcing it into `success` would be a fabrication.
        $event = Telemetry::envelope(['outcome' => 'something-invented-later', 'occurred_at' => 1]);

        $this->assertSame('project.install', $event['type']);
        $this->assertSame('something-invented-later', $event['payload']['status']);
    }

    public function test_each_outcome_is_its_own_event_type(): void
    {
        foreach (
            [
                DeployReport::OUTCOME_SUCCESS => 'project.install.success',
                DeployReport::OUTCOME_FAILED => 'project.install.fail',
                DeployReport::OUTCOME_PARTIAL => 'project.install.partial',
                DeployReport::OUTCOME_RECOVERED => 'project.install.recovered',
                // Named but never transmitted; the local log records it.
                DeployReport::OUTCOME_CANCELLED => 'project.install.cancelled',
            ] as $outcome => $expectedType
        ) {
            $event = Telemetry::envelope(['outcome' => $outcome, 'occurred_at' => 1_700_000_000]);

            $this->assertSame(
                $expectedType,
                $event['type'],
                "outcome '{$outcome}' mapped to the wrong event type"
            );
            // The exact outcome survives in the payload either way.
            $this->assertSame($outcome, $event['payload']['status']);
        }
    }

    public function test_envelope_uses_the_shared_event_shape(): void
    {
        $event = Telemetry::envelope([
            'outcome' => DeployReport::OUTCOME_FAILED,
            'occurred_at' => 1_700_000_000,
            'deploy' => ['source' => 'git'],
        ]);

        // A failure is its own type; see the outcome map below.
        $this->assertSame('project.install.fail', $event['type']);
        $this->assertSame('deploy', $event['payload']['software_op']);
        $this->assertSame('git', $event['payload']['source']);

        // ISO-8601, as the ingest schema requires -- not the raw timestamp.
        $this->assertSame('2023-11-14T22:13:20+00:00', $event['occurred_at']);
    }

    public function test_envelope_keeps_the_whole_report(): void
    {
        $event = Telemetry::envelope([
            'outcome' => DeployReport::OUTCOME_FAILED,
            'occurred_at' => 1_700_000_000,
            'fingerprint' => 'abc123',
            'failure' => ['rule' => 'npm-oom', 'stage' => 'running'],
        ]);

        $this->assertSame('abc123', $event['payload']['fingerprint']);
        $this->assertSame('npm-oom', $event['payload']['failure']['rule']);
    }

    /**
     * The envelope adds fields; it must never quietly replace one the report
     * already carries, or the local line would disagree with the report itself.
     */
    public function test_envelope_does_not_overwrite_report_fields(): void
    {
        $event = Telemetry::envelope([
            'outcome' => DeployReport::OUTCOME_FAILED,
            'occurred_at' => 1_700_000_000,
            'status' => 'report-own-status',
        ]);

        $this->assertSame('report-own-status', $event['payload']['status']);
    }
}
