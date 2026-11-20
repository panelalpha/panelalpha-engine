<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetryShipper;
use App\System\Services\Webserver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/**
 * What ship() does with a report the server asks the source of: the upload it
 * makes, and how each answer to that upload settles the report.
 */
class TelemetryShipperBundleTest extends TestCase
{
    use InMemoryDatabase;

    private const ID = 'report-1';

    private const BUNDLE = "PK\x03\x04 not really a zip, but bytes all the same";

    private string $root;

    /** @var list<array{url: string, method: string, body: string, headers: array<string, list<string>>}> */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-bundle-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/outbox', 0777, true);
        file_put_contents($this->root.'/install-id', str_repeat('c', 32));

        config([
            'telemetry.enabled' => true,
            'telemetry.spool_dir' => $this->root.'/outbox',
            'telemetry.pin_file' => $this->root.'/install-id',
            'telemetry.batch' => 25,
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => '/api/v1/events',
            'system.version' => '9.9.9',
            'app.uid' => 'install-77',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->root.'/outbox', new RecordingRunner));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');

        Telemetry::spool()->put(['id' => self::ID, 'outcome' => DeployReport::OUTCOME_FAILED]);
        file_put_contents((string) Telemetry::spool()->bundlePathFor(self::ID), self::BUNDLE);
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

    /**
     * @param  list<string>  $wanted
     */
    private function ingest(int|\Throwable $upload, array $wanted = [self::ID]): void
    {
        Http::fake(function (Request $request) use ($upload, $wanted) {
            if (! str_ends_with($request->url(), '/source')) {
                return Http::response(['accepted' => [self::ID], 'request_source' => $wanted], 200);
            }
            $this->uploads[] = [
                'url' => $request->url(),
                'method' => $request->method(),
                'body' => $request->body(),
                'headers' => $request->headers(),
            ];
            if ($upload instanceof \Throwable) {
                throw $upload;
            }

            return Http::response('', $upload);
        });
    }

    private function bundleExists(): bool
    {
        return is_file((string) Telemetry::spool()->bundlePathFor(self::ID));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $counts
     */
    private function assertCounts(array $result, array $counts): void
    {
        $this->assertSame($counts, array_intersect_key($result, $counts));
    }

    public function test_a_requested_bundle_is_uploaded_raw_and_the_report_is_done(): void
    {
        $this->ingest(201);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertCounts($result, ['sent' => 1, 'kept' => 0, 'dropped' => 0, 'bundles_sent' => 1, 'bundles_failed' => 0, 'pending' => 0]);
        $this->assertCount(1, $this->uploads);
        $upload = $this->uploads[0];
        $this->assertSame('https://monitoring.test/api/v1/events/report-1/source', $upload['url']);
        $this->assertSame('POST', $upload['method']);
        $this->assertSame(self::BUNDLE, $upload['body']);
        $this->assertSame(['application/zip'], $upload['headers']['Content-Type']);
        $this->assertSame([(string) strlen(self::BUNDLE)], $upload['headers']['Content-Length']);
        $this->assertSame(['PanelAlphaEngine/9.9.9 (deploy-telemetry)'], $upload['headers']['User-Agent']);
        $this->assertSame(['install-77'], $upload['headers']['X-Engine-App-UID']);
        $this->assertFalse($this->bundleExists());
    }

    public function test_a_bundle_carries_the_ingest_token_the_batch_carries(): void
    {
        config(['telemetry.token' => '  ingest-token  ']);
        $this->ingest(201);

        (new TelemetryShipper)->ship();

        $this->assertSame(['Bearer ingest-token'], $this->uploads[0]['headers']['Authorization'] ?? null);
    }

    public function test_a_bundle_the_server_did_not_ask_for_is_not_uploaded(): void
    {
        $this->ingest(201, []);

        $result = (new TelemetryShipper)->ship();

        $this->assertCounts($result, ['sent' => 1, 'kept' => 0, 'bundles_sent' => 0, 'bundles_failed' => 0, 'pending' => 0]);
        $this->assertSame([], $this->uploads);
        $this->assertFalse($this->bundleExists());
    }

    public function test_a_bundle_gone_before_its_upload_is_not_counted_as_sent(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/source')) {
                $this->uploads[] = ['url' => $request->url(), 'method' => $request->method(), 'body' => $request->body(), 'headers' => $request->headers()];

                return Http::response('', 201);
            }
            @unlink((string) Telemetry::spool()->bundlePathFor(self::ID));

            return Http::response(['accepted' => [self::ID], 'request_source' => [self::ID]], 200);
        });

        $result = (new TelemetryShipper)->ship();

        $this->assertCounts($result, ['sent' => 1, 'kept' => 0, 'bundles_sent' => 0, 'bundles_failed' => 0, 'pending' => 0]);
        $this->assertSame([], $this->uploads);
    }

    public function test_a_refused_upload_is_final_and_the_report_is_done(): void
    {
        $this->ingest(403);

        $result = (new TelemetryShipper)->ship();

        $this->assertCounts($result, ['sent' => 1, 'kept' => 0, 'bundles_sent' => 1, 'bundles_failed' => 0, 'pending' => 0]);
        $this->assertFalse($this->bundleExists());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function retryable(): array
    {
        return ['timeout' => [408], 'rate limit' => [429], 'server error' => [500], 'unavailable' => [503], 'redirect' => [304]];
    }

    #[DataProvider('retryable')]
    public function test_an_upload_to_try_again_holds_the_report_and_its_bundle(int $status): void
    {
        $this->ingest($status);

        $result = (new TelemetryShipper)->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertCounts($result, ['sent' => 0, 'kept' => 1, 'bundles_sent' => 0, 'bundles_failed' => 1, 'pending' => 1]);
        $this->assertCount(1, $this->uploads);
        $this->assertTrue($this->bundleExists());
    }

    public function test_an_upload_that_never_arrives_holds_the_report(): void
    {
        $this->ingest(new \RuntimeException('connection reset'));

        $result = (new TelemetryShipper)->ship();

        $this->assertCounts($result, ['sent' => 0, 'kept' => 1, 'bundles_sent' => 0, 'bundles_failed' => 1, 'pending' => 1]);
        $this->assertTrue($this->bundleExists());
    }
}
