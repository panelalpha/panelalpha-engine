<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\RunHeartbeat;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use Illuminate\Support\Facades\DB;

/**
 * A run waiting on the source keeps its claim alive from inside the wait, at
 * most every 30 seconds, so only a dead worker goes quiet. A run that lost
 * its claim aborts the request and leaves the record to the run that took it.
 */
class WordpressMigrationHeartbeatTest extends WordpressMigrationTestCase
{
    private const REMOTE_SYNC = 'https://example.com/wp-json/wp-migrator/v1/remote-sync';

    public function test_a_waiting_run_refreshes_its_heartbeat_at_most_every_thirty_seconds(): void
    {
        $this->freezeSecond();
        $migration = $this->migrationAtRemoteSync();
        $migration->claimRun('run-1');
        $heartbeat = new RunHeartbeat($migration, 'run-1');
        $claimedAt = now()->getTimestamp();

        $this->travel(RunHeartbeat::INTERVAL_SECONDS - 1)->seconds();
        $this->assertTrue($heartbeat->keepAlive());
        $this->assertSame($claimedAt, $this->heartbeatOf($migration));

        $this->travel(1)->seconds();
        $this->assertTrue($heartbeat->keepAlive());
        $this->assertSame(now()->getTimestamp(), $this->heartbeatOf($migration));
        $beatAt = now()->getTimestamp();

        $this->travel(10)->seconds();
        $this->assertTrue($heartbeat->keepAlive());
        $this->assertSame($beatAt, $this->heartbeatOf($migration));
    }

    public function test_a_heartbeat_stops_for_good_once_another_run_took_the_record_over(): void
    {
        $this->freezeSecond();
        $migration = $this->migrationAtRemoteSync();
        $migration->claimRun('run-1');
        $heartbeat = new RunHeartbeat($migration, 'run-1');
        WordpressMigration::query()->whereKey($migration->id)->update(['run_id' => 'run-2']);
        $takenAt = $this->heartbeatOf($migration);

        $this->travel(RunHeartbeat::INTERVAL_SECONDS)->seconds();
        $this->assertFalse($heartbeat->keepAlive());
        $this->assertSame($takenAt, $this->heartbeatOf($migration));

        DB::enableQueryLog();
        $this->assertFalse($heartbeat->keepAlive());
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_remote_sync_longer_than_the_stale_window_keeps_the_run_alive(): void
    {
        $this->freezeSecond();
        $migration = $this->migrationAtRemoteSync();
        $http = new RoutedSourceHttpClient();
        $seen = [];
        $http->on('POST', self::REMOTE_SYNC, function () use ($http, $migration, &$seen): SourceHttpResponse {
            $http->wait(WordpressMigration::STALE_MINUTES + 5, fn () => $this->travel(60)->seconds(), self::REMOTE_SYNC);
            $seen = [
                'stale' => $migration->fresh()->isStale(),
                'heartbeat_age' => now()->getTimestamp() - $this->heartbeatOf($migration),
                'taken_over' => $migration->fresh()->claimRun('another-run'),
            ];

            return new SourceHttpResponse('{"success":true,"migration_id":"m1"}', 200);
        });

        $slice = $this->module($http)->advanceOnce($migration);

        $this->assertSame(MigrationSlice::More, $slice);
        $this->assertSame(['stale' => false, 'heartbeat_age' => 0, 'taken_over' => false], $seen);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOTE_SYNC));
    }

    public function test_a_run_that_lost_its_claim_aborts_the_request_and_leaves_the_record_alone(): void
    {
        $this->freezeSecond();
        $migration = $this->migrationAtRemoteSync();
        $http = new RoutedSourceHttpClient();
        $http->on('POST', self::REMOTE_SYNC, function () use ($http, $migration): SourceHttpResponse {
            WordpressMigration::query()->whereKey($migration->id)->update(['run_id' => 'run-that-took-over']);
            $http->wait(5, fn () => $this->travel(60)->seconds(), self::REMOTE_SYNC);

            return new SourceHttpResponse('{"success":true,"migration_id":"m1"}', 200);
        });

        try {
            $this->module($http)->advanceOnce($migration);
            $this->fail('A run that lost its claim must not finish its step');
        } catch (SourceRequestAborted $e) {
            $this->assertStringContainsString('no longer holds the migration', $e->getMessage());
        }

        $record = $migration->fresh();
        $this->assertSame('run-that-took-over', $record->run_id);
        $this->assertSame(WordpressMigration::STATUS_RUNNING, $record->status);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $record->stepStatus(WordpressMigration::STEP_REMOTE_SYNC));
        $this->assertSame([], $record->step_log);
        $this->assertNull($record->plugin_migration_id);
        $this->assertCount(1, $http->calls);
    }

    public function test_a_finished_run_stops_keeping_its_claim_alive(): void
    {
        $migration = $this->migrationAtRemoteSync();
        $http = (new RoutedSourceHttpClient())
            ->on('POST', self::REMOTE_SYNC, new SourceHttpResponse('{"success":true,"migration_id":"m1"}', 200));

        $this->module($http)->advanceOnce($migration);
        $this->travel(RunHeartbeat::INTERVAL_SECONDS)->seconds();
        $http->request('POST', self::REMOTE_SYNC);

        $this->assertSame(2, $http->count('POST', self::REMOTE_SYNC));
    }

    private function module(RoutedSourceHttpClient $http): WordPressMigrationModule
    {
        $source = new SourceConnection(fn () => $http);

        return new WordPressMigrationModule(
            $this->hermeticSequence([WordpressMigration::STEP_REMOTE_SYNC => new RemoteSyncStep($source)]),
            new NoFailureCleanup(),
            null,
            $source,
        );
    }

    private function migrationAtRemoteSync(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
        );
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);
        $migration->storeMigratorToken('dest-token');
        foreach (WordPressMigrationModule::stepNames() as $step) {
            if ($step === WordpressMigration::STEP_REMOTE_SYNC) {
                break;
            }
            $migration->markStepCompleted($step);
        }

        return $migration;
    }

    private function heartbeatOf(WordpressMigration $migration): ?int
    {
        return $migration->fresh()->heartbeat_at?->getTimestamp();
    }
}
