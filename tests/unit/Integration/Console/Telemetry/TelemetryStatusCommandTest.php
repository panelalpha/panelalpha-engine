<?php

namespace Tests\Integration\Console\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Tests\TestCase;

/** `telemetry:status` prints the configuration, the queue and the platform facts sent with each batch. */
class TelemetryStatusCommandTest extends TestCase
{
    use TelemetrySandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelemetrySandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownTelemetrySandbox();
        parent::tearDown();
    }

    public function test_it_prints_the_configuration_queue_and_platform_facts(): void
    {
        Setting::set(NotificationPreferences::SETTING_NOTIFY_EMAIL, 'ops@example.test');
        Setting::set('cert_domain', 'engine.example.test');
        config(['telemetry.source_bundle.mode' => 'failed']);
        file_put_contents($this->telemetryRoot.'/telemetry-2026-01-01.log', str_repeat('x', 2048));
        Telemetry::spool()->put(['id' => 'report-1', 'outcome' => DeployReport::OUTCOME_FAILED, 'event' => 'deploy']);
        $this->makeUser('shop');

        $this->artisan('telemetry:status')
            ->expectsOutputToContain('yes (telemetry:bug-report)')
            ->expectsOutputToContain('ops@example.test')
            ->expectsOutputToContain('https://engine.example.test')
            ->expectsOutputToContain('1 file(s), 2 KiB')
            ->expectsOutputToContain('2 (+ redacted log tail)')
            ->expectsOutputToContain('every failed deploy (source code IS uploaded)')
            ->expectsOutputToContain(str_repeat('a', 32))
            ->expectsOutputToContain('Platform facts sent with each batch:')
            ->expectsOutputToContain('Ubuntu 24.04 LTS')
            ->expectsOutputToContain('runc, sysbox-runc')
            ->expectsOutputToContain('nginx')
            ->assertExitCode(0);
    }

    public function test_disabled_reporting_says_the_local_log_still_fills(): void
    {
        config([
            'telemetry.enabled' => false,
            'telemetry.tier' => 0,
            'telemetry.source_bundle.mode' => 'unexplained',
            'monitoring.url' => '',
            'logging.channels.telemetry.path' => '',
        ]);

        $this->artisan('telemetry:status')
            ->expectsOutputToContain('(channel not configured)')
            ->expectsOutputToContain('0 (metadata only)')
            ->expectsOutputToContain('unexplained failures only')
            ->expectsOutputToContain('(no monitoring host set')
            ->expectsOutputToContain('Reporting is off.')
            ->assertExitCode(0);
    }

    public function test_tier_one_and_large_queues_are_labelled(): void
    {
        config(['telemetry.tier' => 1]);
        Telemetry::spool()->put(['id' => 'report-big', 'outcome' => DeployReport::OUTCOME_FAILED, 'event' => 'deploy', 'padding' => str_repeat('p', 1024 * 1024)]);

        $this->artisan('telemetry:status')
            ->expectsOutputToContain('1 (+ repository identity)')
            ->expectsOutputToContain('1 MiB')
            ->assertExitCode(0);
    }
}
