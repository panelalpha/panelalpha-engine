<?php

namespace Tests\Integration\Console\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `telemetry:ship` sends the queued reports, or with --dry-run lists what it would send. */
class TelemetryShipCommandTest extends TestCase
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

    private function queue(string $id, array $extra = []): void
    {
        Telemetry::spool()->put([
            'id' => $id,
            'outcome' => DeployReport::OUTCOME_FAILED,
            'event' => 'deploy',
            'deploy' => ['strategy' => 'nodejs'],
            'failure' => ['rule' => 'port-mismatch'],
        ] + $extra);
    }

    public function test_disabled_telemetry_sends_nothing(): void
    {
        config(['telemetry.enabled' => false]);
        $this->queue('report-1');
        Http::fake();

        $this->artisan('telemetry:ship')
            ->expectsOutput('Telemetry is disabled (TELEMETRY_ENABLED=false).')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertCount(1, $this->spooledReports());
    }

    public function test_a_dry_run_lists_the_queue_without_sending(): void
    {
        $this->queue('report-1');
        Http::fake();

        $this->artisan('telemetry:ship --dry-run')
            ->expectsOutput('Would POST 1 report(s) to https://monitoring.test/api/v1/events')
            ->expectsOutputToContain('port-mismatch')
            ->expectsOutputToContain('Bundles are uploaded only for reports the server asks for by id.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_dry_run_of_an_empty_queue_says_so(): void
    {
        $this->artisan('telemetry:ship --dry-run --batch=5')
            ->expectsOutput('Nothing queued.')
            ->assertExitCode(0);
    }

    public function test_a_successful_send_drains_the_queue_and_syncs_preferences(): void
    {
        $this->queue('report-1');
        $this->queue('report-2');
        Http::fake(['*' => Http::response(['accepted' => 2], 200)]);

        $this->artisan('telemetry:ship')
            ->expectsOutputToContain('ok — sent 2, rejected 0, kept 0, dropped 0')
            ->assertExitCode(0);

        $this->assertSame([], $this->spooledReports());
        Http::assertSent(fn ($request): bool => ($request->data()['events'][0]['type'] ?? null) === 'notification.preferences');
    }

    public function test_a_failed_send_keeps_the_reports_and_exits_zero(): void
    {
        $this->queue('report-1');
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('telemetry:ship')
            ->expectsOutputToContain('kept 1')
            ->assertExitCode(0);

        $this->assertCount(1, $this->spooledReports());
    }

    public function test_an_empty_queue_still_reports_a_failed_preferences_sync(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->artisan('telemetry:ship')
            ->expectsOutputToContain('empty — sent 0')
            ->expectsOutputToContain('preferences sync: HTTP 500: nope')
            ->assertExitCode(0);
    }
}
