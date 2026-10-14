<?php

namespace Tests\Unit\System;

use App\Http\Controllers\SystemController;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel reads `default_tunnel` off GET /system/info before it creates a
 * project; the field is written from this one place. The full response is
 * asserted against a live engine by tests/api (see system-info.spec.ts).
 */
class SystemInfoDefaultTunnelTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_it_reports_the_configured_tunnel(): void
    {
        foreach (DomainPlan::TUNNELS as $value) {
            Setting::set(DomainPlan::SETTING_DEFAULT_TUNNEL, $value);

            $this->assertSame($value, SystemController::defaultTunnel());
        }
    }

    public function test_it_reports_null_while_the_setting_is_unset(): void
    {
        $this->assertNull(SystemController::defaultTunnel());
    }
}
