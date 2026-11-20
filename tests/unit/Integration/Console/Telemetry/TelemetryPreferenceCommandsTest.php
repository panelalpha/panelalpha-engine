<?php

namespace Tests\Integration\Console\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** telemetry:enable, :disable, :email and :show: the switches stored in settings and the queue read back. */
class TelemetryPreferenceCommandsTest extends TestCase
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

    public function test_enable_stores_the_switch_and_syncs_monitoring(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('telemetry:enable')
            ->expectsOutput('Telemetry sending enabled.')
            ->expectsOutput('Preferences synced to monitoring')
            ->assertExitCode(0);

        $this->assertSame('1', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        Http::assertSent(fn ($request): bool => ($request->data()['events'][0]['payload']['enabled'] ?? null) === true);
    }

    public function test_enable_fails_when_monitoring_refuses_the_sync(): void
    {
        Http::fake(['*' => Http::response('denied', 403)]);

        $this->artisan('telemetry:enable')
            ->expectsOutput('HTTP 403: denied')
            ->assertExitCode(1);

        $this->assertSame('1', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
    }

    public function test_disable_applies_locally_even_when_the_sync_fails(): void
    {
        config(['monitoring.url' => '']);

        $this->artisan('telemetry:disable')
            ->expectsOutput('Telemetry sending disabled.')
            ->expectsOutput('No monitoring endpoint configured (local disable still applied)')
            ->assertExitCode(0);

        $this->assertSame('0', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
    }

    public function test_disable_tells_monitoring_to_stop(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('telemetry:disable')
            ->expectsOutput('Preferences synced to monitoring')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => ($request->data()['events'][0]['payload']['enabled'] ?? null) === false);
    }

    public function test_email_get_set_and_refusals(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('telemetry:email get')->expectsOutput('(not set)')->assertExitCode(0);
        $this->artisan('telemetry:email set not-an-address')->expectsOutput('Valid email address required')->assertExitCode(1);
        $this->artisan('telemetry:email delete')->expectsOutput('Action must be get or set')->assertExitCode(1);

        $this->artisan('telemetry:email set ops@example.test')
            ->expectsOutput('Notification email set to ops@example.test')
            ->expectsOutput('Preferences synced to monitoring')
            ->assertExitCode(0);
        $this->artisan('telemetry:email GET')->expectsOutput('ops@example.test')->assertExitCode(0);
    }

    public function test_email_set_fails_when_the_sync_fails(): void
    {
        config(['monitoring.url' => '']);

        $this->artisan('telemetry:email set ops@example.test')
            ->expectsOutput('No monitoring endpoint configured')
            ->assertExitCode(1);

        $this->assertSame('ops@example.test', NotificationPreferences::notifyEmail());
    }

    public function test_show_prints_the_newest_or_the_named_report(): void
    {
        $this->artisan('telemetry:show')->expectsOutput('Nothing queued.')->assertExitCode(0);

        Telemetry::spool()->put(['id' => 'report-a', 'outcome' => DeployReport::OUTCOME_FAILED, 'event' => 'deploy', 'marker' => 'first']);
        Telemetry::spool()->put(['id' => 'report-b', 'outcome' => DeployReport::OUTCOME_FAILED, 'event' => 'deploy', 'marker' => 'second']);

        $this->artisan('telemetry:show')->expectsOutputToContain('"marker": "second"')->assertExitCode(0);
        $this->artisan('telemetry:show report-a')->expectsOutputToContain('"marker": "first"')->assertExitCode(0);
        $this->artisan('telemetry:show report-none')->expectsOutput('No queued report with id report-none.')->assertExitCode(1);
    }
}
