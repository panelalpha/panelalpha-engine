<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;
use RuntimeException;

/**
 * Fixture JSON process-batch: database step ends on next_phase === files or
 * boolean finalized; after a network error only on status === completed.
 * The plugin's transfer routes are called with no wp-admin login.
 */
class WordpressMigrationBatchTest extends WordpressMigrationTestCase
{
    public function test_database_loop_ends_on_next_phase_files(): void
    {
        $http = new FakeSourceHttpClient();
        // status: still in database
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'database'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        // process-batch → next_phase files
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => false,
                'phase' => 'database',
                'next_phase' => 'files',
                'progress' => ['percentage' => 100.0, 'current_item' => null, 'next_item' => null],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        (new MigrationTransfer($client))->migrateDatabase('mig-db-1');

        $this->assertTrue($http->calledUrlContaining('process-batch'));
    }

    public function test_database_loop_ends_on_boolean_finalized(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'database'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => true,
                'phase' => 'database',
                'progress' => ['percentage' => 100.0],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        (new MigrationTransfer($client))->migrateDatabase('mig-db-2');

        $this->assertTrue($http->calledUrlContaining('process-batch'));
    }

    public function test_database_early_exit_when_phase_is_files(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'files'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        (new MigrationTransfer($client))->migrateDatabase('mig-db-3');

        $this->assertFalse($http->calledUrlContaining('process-batch'));
    }

    public function test_network_error_succeeds_only_when_status_completed(): void
    {
        $http = new FakeSourceHttpClient();
        // initial status
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'database'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        // process-batch network failure (3 outer attempts × rest-mode fallback = up to 6)
        for ($i = 0; $i < 6; $i++) {
            $http->pushHandler(function (): SourceHttpResponse {
                throw new RuntimeException('network error');
            });
        }
        // status after error → completed
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'completed', 'phase' => 'files'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        (new MigrationTransfer($client))->migrateDatabase('mig-db-4');
        $this->assertTrue(true);
    }

    public function test_network_error_rethrows_when_status_not_completed(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'database'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        for ($i = 0; $i < 6; $i++) {
            $http->pushHandler(function (): SourceHttpResponse {
                throw new RuntimeException('network error');
            });
        }
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'database'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('network error');
        (new MigrationTransfer($client))->migrateDatabase('mig-db-5');
    }

    public function test_files_loop_ends_on_boolean_finalized(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'success' => true,
                'status' => ['status' => 'running', 'phase' => 'files'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => false,
                'phase' => 'files',
                'progress' => ['percentage' => 50.0],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => true,
                'phase' => 'files',
                'progress' => ['percentage' => 100.0],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        (new MigrationTransfer($client))->migrateFiles('mig-files-1');

        $batchCalls = array_filter(
            $http->calls,
            static fn (array $c): bool => str_contains($c['url'], 'process-batch'),
        );
        $this->assertCount(2, $batchCalls);
    }

    public function test_files_loop_reports_progress_on_each_phase_change_and_final_batch(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode(['success' => true, 'status' => ['status' => 'running', 'phase' => 'files', 'files' => ['total_files' => 12]]], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => false,
                'phase' => 'files',
                'progress' => ['percentage' => 40.0, 'current_item' => 'wp-content/a.jpg', 'sent_chunks' => 3, 'total_chunks' => 10],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        // Same phase, inside the throttle window: not reported.
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => false,
                'phase' => 'files',
                'progress' => ['percentage' => 60.0, 'current_item' => 'wp-content/b.jpg'],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => true,
                'phase' => 'files',
                'progress' => ['percentage' => 100.0, 'current_item' => null],
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        $reports = [];
        (new MigrationTransfer(
            $client,
            onProgress: function (TransferProgress $progress) use (&$reports): void {
                $reports[] = [$progress->phase, $progress->phasePercent(), $progress->item, $progress->message()];
            },
        ))->migrateFiles('mig-files-2');

        $this->assertSame([
            ['files', 30.0, 'wp-content/a.jpg', 'Copying the files: 3/10 chunks (12 files)'],
            ['files', 100.0, null, 'Copying the files (12 files)'],
        ], $reports);
    }
}
