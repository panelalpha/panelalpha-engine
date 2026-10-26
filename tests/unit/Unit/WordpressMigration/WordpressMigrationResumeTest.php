<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\Support\JobBudget;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

class WordpressMigrationResumeTest extends WordpressMigrationTestCase
{
    public function test_job_stays_on_the_default_queue_inside_the_queue_budget(): void
    {
        $job = new MigrateWordpress(1);

        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(JobBudget::SECONDS, $job->timeout);
        $this->assertLessThan(JobBudget::RETRY_AFTER_SECONDS, $job->timeout);
        $this->assertGreaterThan(WordpressMigration::STALE_MINUTES * 60, $job->tries * MigrateWordpress::BUSY_RETRY_SECONDS);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1, $job->migrationId);
    }

    public function test_resume_skips_completed_steps_after_simulated_failure(): void
    {
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'resume-secret',
        );

        $failAt = WordpressMigration::STEP_REMOTE_SYNC;
        $completedBeforeFail = [
            WordpressMigration::STEP_RESOLVE_SOURCE,
            WordpressMigration::STEP_CREATE_PROJECT,
            WordpressMigration::STEP_INSTALL_WORDPRESS,
            WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN,
            WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN,
        ];

        $executed = [];
        try {
            $this->advance($migration, $this->recordingSequence($executed, [
                $failAt => new RuntimeException('simulated failure on ' . $failAt),
            ]));
            $this->fail('Expected simulated failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        }

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->status);
        $this->assertSame([...$completedBeforeFail, $failAt], $executed);

        foreach ($completedBeforeFail as $name) {
            $this->assertSame(
                WordpressMigration::STEP_STATUS_COMPLETED,
                $this->stepStatus($migration, $name),
            );
        }
        $this->assertSame(
            WordpressMigration::STEP_STATUS_FAILED,
            $this->stepStatus($migration, $failAt),
        );

        Queue::fake();
        $migration->resume();
        Queue::assertNotPushed(MigrateWordpress::class);

        $executedSecond = [];
        $this->advance($migration, $this->recordingSequence($executedSecond));

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_COMPLETED, $migration->status);
        $this->assertSame(
            [
                WordpressMigration::STEP_REMOTE_SYNC,
                WordpressMigration::STEP_MIGRATE_DATABASE,
                WordpressMigration::STEP_MIGRATE_FILES,
                WordpressMigration::STEP_FINALIZE,
                WordpressMigration::STEP_REMOVE_PLUGINS,
                WordpressMigration::STEP_CLEAR_SECRETS,
            ],
            $executedSecond,
            'Second run must not re-execute steps 1..N-1',
        );
    }

    public function test_resume_reopens_a_failed_record_without_queueing_a_job(): void
    {
        Queue::fake();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
        );
        $migration->status = WordpressMigration::STATUS_FAILED;
        $migration->save();

        $migration->resume();
        Queue::assertNotPushed(MigrateWordpress::class);

        try {
            $migration->resume();
            $this->fail('Expected the second resume to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('failed', $e->getMessage());
        }

        $this->assertSame(WordpressMigration::STATUS_PENDING, $migration->fresh()->status);
        Queue::assertNotPushed(MigrateWordpress::class);
    }

    private function stepStatus(WordpressMigration $migration, string $name): string
    {
        foreach ($migration->steps as $step) {
            if ($step['name'] === $name) {
                return $step['status'];
            }
        }

        $this->fail("Missing step {$name}");
    }
}
