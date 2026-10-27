<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\FinalizeStep;
use Illuminate\Support\Facades\Http;

/**
 * A transfer the source still reports running when finalize starts goes on in
 * the bounded, cancellable runs of the transfer steps, never in one endless loop.
 */
class WordpressMigrationFinalizeBudgetTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    private const STATUS_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/status/mig-fin';

    private const BATCH_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch';

    private const HTACCESS_URL = 'https://example.com/wp-json/wp-migrator/v1/transfer-htaccess';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();
        Http::fake(['*' => Http::response('', 200)]);
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        parent::tearDown();
    }

    public function test_a_transfer_still_running_at_finalize_goes_on_in_bounded_runs(): void
    {
        $migration = $this->atFinalize();
        $batches = 0;
        $http = $this->filesStillRunning($batches, finishedAfter: 5);
        $module = $this->module($http, batchesPerRun: 2);

        $this->assertSame(MigrationSlice::More, $module->advanceOnce($migration));
        $this->assertSame(2, $batches);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_FINALIZE));
        $this->assertSame(0, $http->count('POST', self::HTACCESS_URL));

        $this->assertSame(MigrationSlice::More, $module->advanceOnce($migration->fresh()));
        $this->assertSame(4, $batches);

        $module->advanceOnce($migration->fresh());

        $this->assertSame(5, $batches);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_FINALIZE));
        $this->assertSame(1, $http->count('POST', self::HTACCESS_URL));
    }

    public function test_a_cancel_during_finalize_stops_the_transfer_at_the_next_batch(): void
    {
        $migration = $this->atFinalize();
        $batches = 0;
        $http = $this->filesStillRunning($batches, finishedAfter: PHP_INT_MAX, onBatch: $migration->requestCancel(...));

        $this->assertSame(MigrationSlice::More, $this->module($http, batchesPerRun: 50)->advanceOnce($migration));

        $this->assertSame(1, $batches);
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $migration->fresh()->status);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_FINALIZE));
    }

    private function module(RoutedSourceHttpClient $http, int $batchesPerRun): WordPressMigrationModule
    {
        $source = new SourceConnection(fn () => $http);

        return new WordPressMigrationModule(
            $this->hermeticSequence([
                WordpressMigration::STEP_FINALIZE => new FinalizeStep($source, batchesPerRun: $batchesPerRun),
                WordpressMigration::STEP_REMOVE_PLUGINS => new NoOpStep(WordpressMigration::STEP_REMOVE_PLUGINS),
            ]),
            new NoFailureCleanup,
            null,
            $source,
        );
    }

    private function atFinalize(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
            sourceToken: str_repeat('ef', 32),
        );
        foreach ($migration->steps as $step) {
            if ($step['name'] === WordpressMigration::STEP_FINALIZE) {
                break;
            }
            $migration->markStepCompleted($step['name']);
        }
        $migration->storePluginMigrationId('mig-fin');
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    /**
     * @param  (callable(): mixed)|null  $onBatch
     */
    private function filesStillRunning(int &$batches, int $finishedAfter, ?callable $onBatch = null): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, new SourceHttpResponse(
                json_encode(['success' => true, 'status' => ['status' => 'running', 'phase' => 'files']], JSON_THROW_ON_ERROR),
                200,
            ))
            ->on('POST', self::BATCH_URL, function () use (&$batches, $finishedAfter, $onBatch): SourceHttpResponse {
                $batches++;
                if ($onBatch !== null) {
                    $onBatch();
                }

                return new SourceHttpResponse(json_encode([
                    'finalized' => $batches >= $finishedAfter,
                    'phase' => 'files',
                    'progress' => ['percentage' => 50],
                ], JSON_THROW_ON_ERROR), 200);
            })
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));
    }
}
