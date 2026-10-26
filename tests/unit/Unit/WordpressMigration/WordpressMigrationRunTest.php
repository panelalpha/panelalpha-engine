<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateFilesStep;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

/**
 * A run claims the record, does one step or a bounded share of a batched
 * one, and hands over to the next job; a dead run's record goes stale and
 * may be resumed or taken over.
 */
class WordpressMigrationRunTest extends WordpressMigrationTestCase
{
    public function test_one_run_does_one_step_and_asks_for_the_next(): void
    {
        $migration = $this->migration();
        $executed = [];
        $module = new WordPressMigrationModule($this->recordingSequence($executed), new NoFailureCleanup());

        $this->assertSame(MigrationSlice::More, $module->advanceOnce($migration));
        $this->assertSame([WordpressMigration::STEP_RESOLVE_SOURCE], $executed);
        $this->assertSame(WordpressMigration::STATUS_RUNNING, $migration->fresh()->status);
        $this->assertNull($migration->fresh()->run_id);
    }

    public function test_a_record_held_by_a_live_run_is_not_run_twice(): void
    {
        $migration = $this->migration();
        $this->assertTrue($migration->claimRun('other-run'));
        $executed = [];

        $slice = (new WordPressMigrationModule($this->recordingSequence($executed), new NoFailureCleanup()))->advanceOnce($migration);

        $this->assertSame(MigrationSlice::Busy, $slice);
        $this->assertSame([], $executed);
        $this->assertFalse($migration->claimRun('third-run'));
    }

    public function test_a_stale_holder_is_taken_over(): void
    {
        $migration = $this->migration();
        $migration->claimRun('dead-run');

        $this->travel(WordpressMigration::STALE_MINUTES + 1)->minutes();

        $this->assertTrue($migration->claimRun('new-run'));
        $this->assertSame('new-run', $migration->fresh()->run_id);
    }

    public function test_the_job_queues_the_next_job_until_the_run_is_done(): void
    {
        Queue::fake();
        $migration = $this->migration();
        $executed = [];
        $this->app->instance(WordPressMigrationModule::class, new WordPressMigrationModule($this->recordingSequence($executed), new NoFailureCleanup()));

        (new MigrateWordpress($migration->id))->handle();

        $this->assertSame([WordpressMigration::STEP_RESOLVE_SOURCE], $executed);
        Queue::assertPushed(MigrateWordpress::class, fn (MigrateWordpress $job): bool => $job->migrationId === $migration->id && $job->queue === 'default');
    }

    public function test_the_last_step_completes_the_run_and_queues_nothing(): void
    {
        Queue::fake();
        $migration = $this->migration();
        foreach (array_slice($migration->steps, 0, -1) as $step) {
            $migration->markStepCompleted($step['name']);
        }
        $this->app->instance(WordPressMigrationModule::class, new WordPressMigrationModule($this->hermeticSequence(), new NoFailureCleanup()));

        (new MigrateWordpress($migration->id))->handle();

        $this->assertSame(WordpressMigration::STATUS_COMPLETED, $migration->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_batched_step_spreads_over_runs_and_heartbeats(): void
    {
        $migration = $this->atFiles();
        $http = $this->filesInBatches(3);
        $step = new MigrateFilesStep(new SourceConnection(fn () => $http), batchesPerRun: 1);
        $module = new WordPressMigrationModule($this->hermeticSequence([WordpressMigration::STEP_MIGRATE_FILES => $step]), new NoFailureCleanup());

        $this->assertSame(MigrationSlice::More, $module->advanceOnce($migration));
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_MIGRATE_FILES));
        $this->assertSame(WordpressMigration::STEP_MIGRATE_FILES, $migration->fresh()->progress['step']);
        $this->assertNotNull($migration->fresh()->heartbeat_at);

        $module->advanceOnce($migration->fresh());
        $module->advanceOnce($migration->fresh());

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus(WordpressMigration::STEP_MIGRATE_FILES));
        $this->assertSame(3, $http->count('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch'));
    }

    public function test_a_step_that_returns_unfinished_without_being_batched_fails(): void
    {
        $migration = $this->migration();
        $lazy = new class implements MigrationStep {
            public function name(): string
            {
                return WordpressMigration::STEP_RESOLVE_SOURCE;
            }

            public function run(WordpressMigration $migration): void
            {
            }
        };

        $this->expectException(RuntimeException::class);
        try {
            (new WordPressMigrationModule($this->hermeticSequence([WordpressMigration::STEP_RESOLVE_SOURCE => $lazy]), new NoFailureCleanup()))->advanceOnce($migration);
        } finally {
            $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->fresh()->status);
        }
    }

    public function test_a_running_record_resumes_only_once_it_is_stale(): void
    {
        $migration = $this->migration();
        $migration->claimRun('dead-run');
        $migration->markRunning();
        $migration->storeProgress(WordpressMigration::STEP_MIGRATE_FILES);

        try {
            $migration->resume();
            $this->fail('A running record that still reports must not be resumed');
        } catch (InvalidArgumentException) {
            $this->assertFalse($migration->fresh()->toStatusArray()['stale']);
        }

        $this->travel(WordpressMigration::STALE_MINUTES + 1)->minutes();
        $this->assertTrue($migration->fresh()->toStatusArray()['stale']);

        $migration->resume();

        $this->assertSame(WordpressMigration::STATUS_PENDING, $migration->fresh()->status);
        $this->assertNull($migration->fresh()->run_id);
    }

    public function test_a_failure_hands_the_failed_step_to_the_cleanup_and_keeps_the_secrets(): void
    {
        $migration = $this->migration();
        $cleanup = new NoFailureCleanup();
        $executed = [];
        $sequence = $this->recordingSequence($executed, [WordpressMigration::STEP_RESOLVE_SOURCE => new RuntimeException('unreachable')]);

        try {
            (new WordPressMigrationModule($sequence, $cleanup))->advanceOnce($migration);
            $this->fail('Expected the step to fail');
        } catch (RuntimeException) {
        }

        $this->assertSame([WordpressMigration::STEP_RESOLVE_SOURCE], $cleanup->failedSteps);
        $this->assertSame('secret', $migration->fresh()->secretsForWorker()['password']);
    }

    private function migration(): WordpressMigration
    {
        return WordpressMigration::start('https://example.com', 'admin', 'secret', MigrationTarget::newProject('example.com', null));
    }

    private function atFiles(): WordpressMigration
    {
        $migration = $this->migration();
        foreach ($migration->steps as $step) {
            if ($step['name'] === WordpressMigration::STEP_MIGRATE_FILES) {
                break;
            }
            $migration->markStepCompleted($step['name']);
        }
        $migration->storePluginMigrationId('mig-files');
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function filesInBatches(int $batches): RoutedSourceHttpClient
    {
        $sent = 0;

        return (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-json/wp-migrator/v1/remote-sync/status/mig-files', new SourceHttpResponse(
                json_encode(['success' => true, 'status' => ['status' => 'running', 'phase' => 'files']], JSON_THROW_ON_ERROR),
                200,
            ))
            ->on('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch', function () use (&$sent, $batches): SourceHttpResponse {
                $sent++;

                return new SourceHttpResponse(json_encode([
                    'finalized' => $sent >= $batches,
                    'phase' => 'files',
                    'progress' => ['percentage' => 100 * $sent / $batches, 'sent_chunks' => $sent, 'total_chunks' => $batches],
                ], JSON_THROW_ON_ERROR), 200);
            });
    }
}
