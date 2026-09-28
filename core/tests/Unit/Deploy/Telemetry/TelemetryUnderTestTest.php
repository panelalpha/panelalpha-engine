<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Tests\TestCase;

/**
 * engine#276: run on an installed host, the suite reads the live database, so
 * the operator's `telemetry:enable` switched telemetry on for the tests too,
 * and their fixture deploys landed in the host's telemetry log.
 */
class TelemetryUnderTestTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::setRuntimeSettings([]);
        parent::tearDown();
    }

    public function test_a_stored_enable_does_not_switch_telemetry_on_for_a_test(): void
    {
        config(['telemetry.enabled' => false]);
        Setting::setRuntimeSettings([NotificationPreferences::SETTING_TELEMETRY_ENABLED => '1']);

        $this->assertTrue(NotificationPreferences::isTelemetryEnabled(), 'the override itself still works');
        $this->assertFalse(Telemetry::enabled());
    }

    public function test_a_test_that_asks_for_telemetry_still_gets_it(): void
    {
        config(['telemetry.enabled' => true]);

        $this->assertTrue(Telemetry::enabled());
    }

    public function test_a_captured_deploy_does_not_reach_the_local_log(): void
    {
        $path = storage_path('logs/telemetry-' . date('Y-m-d') . '.log');
        $before = is_file($path) ? (int) filesize($path) : 0;

        $record = new \ReflectionMethod(Telemetry::class, 'record');
        $record->invoke(null, ['outcome' => 'failed', 'occurred_at' => time()]);

        clearstatcache();
        $this->assertSame($before, is_file($path) ? (int) filesize($path) : 0);
    }
}
