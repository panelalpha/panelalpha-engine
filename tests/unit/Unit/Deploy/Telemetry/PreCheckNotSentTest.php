<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetryFields;
use Tests\TestCase;

/**
 * A precheck refusing the host (too little disk, too little RAM) happens
 * before anything is deployed. It stays in the local record, but it is not a
 * deploy failure and must not be sent as one.
 */
class PreCheckNotSentTest extends TestCase
{
    public function test_a_precheck_rejection_is_not_sendable(): void
    {
        $sendable = new \ReflectionMethod(Telemetry::class, 'isSendable');
        $result = $sendable->invoke(
            null,
            new TelemetryFields('nobody'),
            DeployReport::OUTCOME_FAILED,
            ['stage' => 'cloning', DeployLogger::PRECHECK_REJECTED => true]
        );

        $this->assertFalse($result);
    }

    public function test_only_a_true_mark_counts(): void
    {
        $this->assertTrue(DeployReport::isPreCheckRejection([DeployLogger::PRECHECK_REJECTED => true]));
        $this->assertFalse(DeployReport::isPreCheckRejection([]));
        $this->assertFalse(DeployReport::isPreCheckRejection([DeployLogger::PRECHECK_REJECTED => 'yes']));
    }
}
