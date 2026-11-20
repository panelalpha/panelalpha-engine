<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetryShipper;
use App\System\Services\Webserver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * Every status ship() answers, and how each settles the spool; sendNow() when it cannot send.
 */
class TelemetryShipperOutcomesTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-ship-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/outbox', 0777, true);
        file_put_contents($this->root.'/install-id', str_repeat('d', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.spool_dir' => $this->root.'/outbox',
            'telemetry.pin_file' => $this->root.'/install-id',
            'telemetry.batch' => 25,
            'telemetry.failure_timeout' => 5,
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'system.version' => '9.9.9',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->root.'/outbox', new RecordingRunner));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/outbox/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root.'/outbox');
        @unlink($this->root.'/install-id');
        @rmdir($this->root);
        Telemetry::resetCache();
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);

        parent::tearDown();
    }

    private function queue(string ...$ids): void
    {
        foreach ($ids as $id) {
            Telemetry::spool()->put(['id' => $id, 'outcome' => DeployReport::OUTCOME_FAILED]);
        }
    }

    private function noInstallId(): void
    {
        config(['telemetry.pin_file' => $this->root.'/missing/install-id']);
        (new ReflectionProperty(Telemetry::class, 'installId'))->setValue(null, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeOf(string $id): array
    {
        return (array) Telemetry::spool()->read($this->root.'/outbox/'.$id.'.json');
    }

    public function test_telemetry_off_ships_nothing(): void
    {
        config(['telemetry.enabled' => false]);
        $this->queue('r1');
        Http::fake();

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $result['pending']);
        Http::assertNothingSent();
    }

    public function test_no_install_id_holds_every_report(): void
    {
        $this->noInstallId();
        $this->queue('r1', 'r2');
        Http::fake();

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('no-install-id', $result['status']);
        $this->assertSame(2, $result['pending']);
        Http::assertNothingSent();
    }

    public function test_no_monitoring_host_holds_every_report(): void
    {
        config(['monitoring.url' => '']);
        $this->queue('r1');
        Http::fake();

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('no-endpoint', $result['status']);
        $this->assertSame(1, $result['pending']);
        Http::assertNothingSent();
    }

    public function test_an_empty_spool_sends_nothing(): void
    {
        Http::fake();

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('empty', $result['status']);
        Http::assertNothingSent();
    }

    public function test_an_ingest_nobody_reached_defers_without_spending_attempts(): void
    {
        $this->queue('r1', 'r2');
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('unreachable', $result['status']);
        $this->assertSame('Could not resolve host', $result['message']);
        $this->assertSame(2, $result['kept']);
        $this->assertSame(2, $result['pending']);
        $this->assertSame(0, $this->envelopeOf('r1')['attempts']);
        $this->assertSame(1, $this->envelopeOf('r1')['deferrals']);
    }

    public function test_a_refusing_4xx_drops_the_batch(): void
    {
        $this->queue('r1', 'r2');
        Http::fake(['*' => Http::response(str_repeat('x', 400), 422)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(2, $result['dropped']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame('HTTP 422: '.str_repeat('x', 300), $result['message']);
    }

    public function test_a_server_error_keeps_the_batch_and_counts_an_attempt(): void
    {
        $this->queue('r1');
        Http::fake(['*' => Http::response('busy', 503)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('retry', $result['status']);
        $this->assertSame('HTTP 503', $result['message']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(1, $result['pending']);
        $this->assertSame(1, $this->envelopeOf('r1')['attempts']);
    }

    public function test_a_missing_route_is_kept_rather_than_dropped(): void
    {
        $this->queue('r1');
        Http::fake(['*' => Http::response('', 404)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('retry', $result['status']);
        $this->assertSame(1, $result['pending']);
    }

    public function test_a_report_retried_for_the_last_time_is_dropped(): void
    {
        $this->queue('r1');
        $path = $this->root.'/outbox/r1.json';
        $envelope = $this->envelopeOf('r1');
        $envelope['attempts'] = Spool::MAX_ATTEMPTS - 1;
        file_put_contents($path, json_encode($envelope));
        Http::fake(['*' => Http::response('busy', 500)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame(0, $result['kept']);
        $this->assertSame(1, $result['dropped']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_the_verdict_takes_ids_given_as_objects_or_scalars(): void
    {
        $this->queue('r1', 'r2', 'r3', 'r4');
        Http::fake(['*' => Http::response([
            'accepted' => ['r3', ['nested'], 'r4'],
            'rejected' => [['id' => 'r1'], 'r2', ['id' => ['bad']]],
        ], 200)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['rejected']);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['pending']);
    }

    public function test_a_report_left_out_of_accepted_spends_an_attempt(): void
    {
        $this->queue('r1', 'r2');
        Http::fake(['*' => Http::response(['accepted' => ['r1']], 200)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(1, $this->envelopeOf('r2')['attempts']);
    }

    public function test_a_scalar_body_takes_the_whole_batch(): void
    {
        $this->queue('r1');
        Http::fake(['*' => Http::response('"ok"', 200)]);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['pending']);
    }

    public function test_a_requested_bundle_whose_file_is_gone_settles_the_report(): void
    {
        $this->queue('r1');
        $bundle = (string) Telemetry::spool()->bundlePathFor('r1');
        file_put_contents($bundle, 'PK');
        Http::fake(function ($request) use ($bundle) {
            @unlink($bundle);

            return Http::response(['accepted' => ['r1'], 'request_source' => ['r1']], 200);
        });

        $result = (new TelemetryShipper)->ship();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['bundles_failed']);
        Http::assertSentCount(1);
    }

    public function test_send_now_without_an_install_id_sends_nothing(): void
    {
        $this->noInstallId();
        Http::fake();

        $this->assertSame(['sent' => false, 'problem' => null], (new TelemetryShipper)->sendNow(['id' => 'r1', 'outcome' => 'failed']));
        Http::assertNothingSent();
    }

    public function test_send_now_without_a_monitoring_host_sends_nothing(): void
    {
        config(['monitoring.url' => '']);
        Http::fake();

        $this->assertSame(['sent' => false, 'problem' => null], (new TelemetryShipper)->sendNow(['id' => 'r1', 'outcome' => 'failed']));
        Http::assertNothingSent();
    }

    public function test_send_now_that_cannot_connect_leaves_it_to_the_spool(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertSame(['sent' => false, 'problem' => null], (new TelemetryShipper)->sendNow(['id' => 'r1', 'outcome' => 'failed']));
    }

    public function test_send_now_the_server_did_not_accept_leaves_it_to_the_spool(): void
    {
        Http::fake(['*' => Http::response(['accepted' => ['someone-else']], 200)]);

        $this->assertSame(['sent' => false, 'problem' => null], (new TelemetryShipper)->sendNow(['id' => 'r1', 'outcome' => 'failed']));
    }

    public function test_send_now_the_server_rejected_is_sent_with_no_problem(): void
    {
        Http::fake(['*' => Http::response(['accepted' => [], 'rejected' => ['r1']], 200)]);

        $this->assertSame(['sent' => true, 'problem' => null], (new TelemetryShipper)->sendNow(['id' => 'r1', 'outcome' => 'failed']));
    }

    public function test_a_problem_skips_entries_for_other_events_and_needs_a_title_or_fix(): void
    {
        $body = ['results' => [
            'junk',
            ['index' => 1, 'problem' => ['title' => 'Other event']],
            ['index' => 0, 'problem' => 'not an object'],
            ['index' => 0, 'fingerprint' => 'other', 'problem' => ['title' => 'Other fingerprint']],
            ['index' => 0, 'problem' => ['title' => 'Mine', 'body_fix' => '  ', 'status' => 7]],
        ]];

        $problem = TelemetryShipper::problem($body, 'fp');

        $this->assertSame('Mine', $problem['title']);
        $this->assertNull($problem['body_fix']);
        $this->assertNull($problem['status']);
        $this->assertNull(TelemetryShipper::problem(['results' => [['index' => 0, 'problem' => ['body_why' => 'why']]]], 'fp'));
        $this->assertNull(TelemetryShipper::problem(['results' => 'none'], 'fp'));
        $this->assertNull(TelemetryShipper::problem('body', 'fp'));
    }
}
