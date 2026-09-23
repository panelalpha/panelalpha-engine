<?php

namespace Tests\Feature;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetryShipper;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the shipper does with the spool, per response.
 *
 * Characterisation tests: the class had none, and its two longest methods are
 * the ones deciding whether a queued report is sent, kept for a retry, or
 * dropped for good. Getting that wrong either loses reports silently or
 * retries a rejected one until the disk fills, and neither shows up anywhere
 * except as a spool that never drains.
 *
 * Nothing here asserts the wire format — only which of those three things
 * happens, because that is the part a refactor can quietly invert.
 */
class TelemetryShipperTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolDir = sys_get_temp_dir() . '/pa-shipper-' . bin2hex(random_bytes(6));
        mkdir($this->spoolDir, 0777, true);

        config([
            'telemetry.enabled' => true,
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'telemetry.spool_dir' => $this->spoolDir,
            'telemetry.batch' => 25,
        ]);
        Telemetry::resetCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
        Telemetry::resetCache();

        parent::tearDown();
    }

    private function queue(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            // put() keys the file on the report id and refuses anything else.
            // `outcome` is not decoration here: Spool::pending() drops an
            // envelope without one rather than ship a payload the ingest
            // would refuse, and every real report carries it.
            Telemetry::spool()->put([
                'id' => 'report-' . $i,
                'outcome' => DeployReport::OUTCOME_FAILED,
                'event' => 'deploy',
            ]);
        }
    }

    private function pendingCount(): int
    {
        return Telemetry::spool()->stats()['count'];
    }

    public function test_it_does_nothing_when_telemetry_is_disabled(): void
    {
        config(['telemetry.enabled' => false]);
        $this->queue();
        Http::fake();

        $this->assertSame('disabled', (new TelemetryShipper())->ship()['status']);
        Http::assertNothingSent();
        $this->assertSame(1, $this->pendingCount(), 'a disabled shipper must not drain the spool');
    }

    public function test_it_refuses_to_ship_without_a_monitoring_host(): void
    {
        config(['monitoring.url' => '']);
        $this->queue();
        Http::fake();

        $this->assertSame('no-endpoint', (new TelemetryShipper())->ship()['status']);
        Http::assertNothingSent();
        $this->assertSame(1, $this->pendingCount());
    }

    public function test_the_batch_goes_to_monitoring_plus_the_configured_route(): void
    {
        $this->queue();
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        (new TelemetryShipper())->ship();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://monitoring.test/api/v1/events');
    }

    public function test_the_batch_carries_the_app_uid_header(): void
    {
        config(['app.uid' => 'install-42']);
        // runtime settings keep this one off the database
        Setting::setRuntimeSettings(['telemetry_enabled' => null, 'license_key' => '']);
        $this->queue();
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        (new TelemetryShipper())->ship();

        Http::assertSent(fn ($request): bool => $request->header('X-Engine-App-UID') === ['install-42']);
        Setting::clearRuntimeSettings();
    }

    public function test_a_connect_url_with_a_trailing_slash_does_not_move_reports(): void
    {
        config(['connect.url' => 'https://connect.test/']);
        $this->queue();
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        (new TelemetryShipper())->ship();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://monitoring.test/api/v1/events');
    }

    public function test_an_empty_spool_is_not_a_failure(): void
    {
        Http::fake();

        $result = (new TelemetryShipper())->ship();

        $this->assertSame('empty', $result['status']);
        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_an_accepted_batch_leaves_the_spool_empty(): void
    {
        $this->queue(3);
        Http::fake(['*' => Http::response(['accepted' => 3], 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $this->pendingCount(), 'accepted reports must not be retried');
    }

    /**
     * `queue:` in the command's summary line is what is still waiting, not
     * what was waiting when the run started. Reported before the send, a
     * clean ship printed "sent 1 ... (queue: 1)" and read as though the
     * report had not gone anywhere.
     */
    public function test_the_queue_it_reports_is_what_is_left_afterwards(): void
    {
        $this->queue(2);
        Http::fake(['*' => Http::response(['accepted' => 2], 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['pending'], 'an accepted batch leaves nothing queued');
        $this->assertSame($this->pendingCount(), $result['pending']);
    }

    public function test_a_kept_batch_still_counts_as_queued(): void
    {
        $this->queue(2);
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(2, $result['pending']);
        $this->assertSame($this->pendingCount(), $result['pending']);
    }

    public function test_a_report_the_server_names_as_rejected_is_forgotten(): void
    {
        // Rejected is not "failed": the server has looked at it and will not
        // take it, so holding it would retry something already refused.
        $this->queue(2);
        Http::fake(['*' => Http::response(['rejected' => ['report-0']], 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(1, $result['rejected']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_a_report_the_server_leaves_out_of_accepted_is_kept(): void
    {
        // Naming some ids means the rest were not taken. They stay queued.
        $this->queue(2);
        Http::fake(['*' => Http::response(['accepted' => ['report-0']], 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(1, $this->pendingCount());
    }

    public function test_naming_no_accepted_ids_means_the_whole_batch_was_taken(): void
    {
        // The distinction that makes `accepted` nullable: a server answering
        // 2xx with no per-report detail has accepted all of them, and treating
        // that as "accepted none" would retry every report forever.
        $this->queue(3);
        Http::fake(['*' => Http::response(['note' => 'thanks'], 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(3, $result['sent']);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_a_malformed_body_does_not_lose_the_batch(): void
    {
        // The response comes from somewhere else. Anything that is not a
        // scalar id is dropped rather than coerced into one.
        $this->queue(2);
        Http::fake(['*' => Http::response('not json at all', 200)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['sent']);
    }

    public function test_a_server_error_keeps_the_batch_for_a_retry(): void
    {
        // The ingest being down is our problem, not the report's.
        $this->queue(2);
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertNotSame('ok', $result['status']);
        $this->assertSame(2, $this->pendingCount(), 'a 5xx must not lose reports');
    }

    public function test_a_rejected_batch_is_dropped_rather_than_retried(): void
    {
        // A 4xx means this payload will never be accepted. Retrying itfills
        // the spool with something the server has already refused.
        $this->queue(2);
        Http::fake(['*' => Http::response(['error' => 'bad schema'], 422)]);

        $result = (new TelemetryShipper())->ship();

        $this->assertSame(2, $result['dropped']);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_rate_limiting_is_kept_not_dropped(): void
    {
        // 429 and 408 are the two 4xx that mean "try again", and treating them
        // like the rest would throw away reports the server asked us to resend.
        foreach ([408, 429] as $status) {
            $this->queue(1);
            Http::fake(['*' => Http::response('slow down', $status)]);

            (new TelemetryShipper())->ship();

            $this->assertSame(1, $this->pendingCount(), "HTTP {$status} must keep the batch");
            foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    public function test_an_unreachable_endpoint_keeps_the_batch(): void
    {
        $this->queue(2);
        Http::fake(function (): void {
            throw new \RuntimeException('dns failure');
        });

        $result = (new TelemetryShipper())->ship();

        $this->assertSame('unreachable', $result['status']);
        $this->assertStringContainsString('dns', (string) $result['message']);
        $this->assertSame(2, $this->pendingCount());
    }

    /**
     * The case monitoring creates today: every other route is live, the reports
     * one is not built yet, so it answers 405. That is not a verdict on the
     * payload, and treating it as one would empty every spool in the fleet on
     * the first cron run after monitoring became reachable.
     */
    public function test_a_missing_ingest_route_is_kept_not_dropped(): void
    {
        foreach ([404, 405] as $status) {
            $this->queue();
            Http::fake(['*' => Http::response('no route here', $status)]);

            $result = (new TelemetryShipper())->ship();

            $this->assertSame('retry', $result['status'], "HTTP {$status} must be retried");
            $this->assertSame(0, $result['dropped'], "HTTP {$status} must not drop a report");
            $this->assertSame(1, $this->pendingCount());
            Telemetry::spool()->forget(glob($this->spoolDir . '/*.json')[0]);
        }
    }

    /**
     * The case the default monitoring host creates until it serves: nothing answers, ever.
     *
     * A counted failure would empty the spool after Spool::MAX_ATTEMPTS, so an
     * engine pointed at a monitoring host that does not resolve yet would destroy every
     * report it took. A deferral backs off instead, and the reports survive to
     * be sent the day monitoring answers.
     */
    public function test_an_ingest_that_never_answers_never_drops_a_report(): void
    {
        $this->queue();
        Http::fake(function (): void {
            throw new \RuntimeException('dns failure');
        });

        $shipper = new TelemetryShipper();
        for ($attempt = 0; $attempt < Spool::MAX_ATTEMPTS + 3; $attempt++) {
            // Backoff is what pending() honours, and it grows with each
            // deferral; the report is only offered again once it has elapsed.
            $this->clearBackoff();
            $result = $shipper->ship();
            $this->assertSame('unreachable', $result['status']);
            $this->assertSame(0, $result['dropped']);
        }

        $this->assertSame(1, $this->pendingCount(), 'a monitoring host that never answers must not cost a report');
    }

    /** Pretend the backoff for every queued report has elapsed. */
    private function clearBackoff(): void
    {
        foreach (glob($this->spoolDir . '/*.json') ?: [] as $file) {
            $envelope = json_decode((string) file_get_contents($file), true);
            if (!is_array($envelope)) {
                continue;
            }
            unset($envelope['last_attempt_at']);
            file_put_contents($file, (string) json_encode($envelope));
        }
    }
}
