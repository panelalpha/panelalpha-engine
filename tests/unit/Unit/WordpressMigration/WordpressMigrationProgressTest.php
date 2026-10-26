<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;

/**
 * progress answers the current step and one percent over the run, weighted
 * setup 0-10, database 10-40, files 40-95 and finish 95-100, with the chunk
 * counts the plugin reports.
 */
class WordpressMigrationProgressTest extends WordpressMigrationTestCase
{
    public function test_a_new_migration_is_at_zero_on_its_first_step(): void
    {
        $progress = $this->migration()->toStatusArray()['progress'];

        $this->assertSame(WordpressMigration::STEP_RESOLVE_SOURCE, $progress['step']);
        $this->assertSame(1, $progress['step_index']);
        $this->assertSame(11, $progress['step_count']);
        $this->assertSame(0.0, $progress['percent']);
        $this->assertNull($progress['updated_at']);
    }

    public function test_setup_steps_share_the_first_ten_percent(): void
    {
        $migration = $this->completedThrough(WordpressMigration::STEP_INSTALL_WORDPRESS);

        $progress = $migration->toStatusArray()['progress'];

        $this->assertSame(WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN, $progress['step']);
        $this->assertSame(5.0, $progress['percent']);
        $this->assertSame(50.0, $progress['phase_percent']);
    }

    public function test_the_database_and_files_phases_are_weighted_by_their_chunks(): void
    {
        $migration = $this->completedThrough(WordpressMigration::STEP_REMOTE_SYNC);
        $migration->storeTransferProgress(
            WordpressMigration::STEP_MIGRATE_DATABASE,
            new TransferProgress('database', 10.0, 'wp_posts', 50, 100, 12),
        );

        $database = $migration->fresh()->toStatusArray()['progress'];

        $this->assertSame(25.0, $database['percent']);
        $this->assertSame(50.0, $database['phase_percent']);
        $this->assertSame('wp_posts', $database['item']);
        $this->assertSame('Copying the database: 50/100 chunks (12 tables)', $database['message']);

        $migration = $this->completedThrough(WordpressMigration::STEP_MIGRATE_DATABASE, $migration->fresh());
        $migration->storeTransferProgress(
            WordpressMigration::STEP_MIGRATE_FILES,
            new TransferProgress('files', 0.0, 'wp-content/a.jpg', 1, 4),
        );

        $this->assertSame(53.8, $migration->fresh()->toStatusArray()['progress']['percent']);
    }

    public function test_a_stored_report_of_another_step_is_not_shown(): void
    {
        $migration = $this->completedThrough(WordpressMigration::STEP_MIGRATE_DATABASE);
        $migration->storeTransferProgress(WordpressMigration::STEP_MIGRATE_DATABASE, new TransferProgress('database', 100.0, 'wp_users'));

        $progress = $migration->fresh()->toStatusArray()['progress'];

        $this->assertSame(WordpressMigration::STEP_MIGRATE_FILES, $progress['step']);
        $this->assertSame(40.0, $progress['percent']);
        $this->assertNull($progress['item']);
        $this->assertSame('Copying the files', $progress['message']);
    }

    public function test_finish_steps_run_from_ninety_five_and_a_completed_run_is_at_one_hundred(): void
    {
        $migration = $this->completedThrough(WordpressMigration::STEP_MIGRATE_FILES);
        $this->assertSame(95.0, $migration->toStatusArray()['progress']['percent']);

        $executed = [];
        $this->advance($migration, $this->recordingSequence($executed));

        $progress = $migration->fresh()->toStatusArray()['progress'];
        $this->assertSame(100.0, $progress['percent']);
        $this->assertSame(WordpressMigration::STEP_CLEAR_SECRETS, $progress['step']);
        $this->assertNotNull($progress['updated_at']);
    }

    public function test_every_step_the_run_starts_beats_the_heartbeat(): void
    {
        $migration = $this->migration();
        $seen = [];
        $sequence = $this->hermeticSequence([
            WordpressMigration::STEP_CREATE_PROJECT => new CallbackStep(
                WordpressMigration::STEP_CREATE_PROJECT,
                function (WordpressMigration $running) use (&$seen): void {
                    $seen[] = $running->fresh()->progress;
                },
            ),
        ]);

        $this->advance($migration, $sequence);

        $this->assertSame(WordpressMigration::STEP_CREATE_PROJECT, $seen[0]['step']);
        $this->assertSame('Creating the project', $seen[0]['message']);
        $this->assertNotNull($seen[0]['updated_at']);
    }

    public function test_the_client_keeps_the_chunk_counts_of_a_batch(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            json_encode([
                'finalized' => false,
                'phase' => 'database',
                'progress' => ['percentage' => 12.5, 'current_item' => 'wp_posts', 'sent_chunks' => 7, 'total_chunks' => 20],
            ], JSON_THROW_ON_ERROR),
            200,
        ));
        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));

        $batch = $client->processBatch('mig-1');

        $this->assertSame(7, $batch['sent_chunks']);
        $this->assertSame(20, $batch['total_chunks']);
        $this->assertSame(35.0, TransferProgress::fromBatch($batch, [])->phasePercent());
    }

    private function migration(): WordpressMigration
    {
        return WordpressMigration::start(
            'https://example.com',
            'admin',
            'secret',
            MigrationTarget::newProject('example.com', null),
        );
    }

    private function completedThrough(string $last, ?WordpressMigration $migration = null): WordpressMigration
    {
        $migration ??= $this->migration();
        foreach ($migration->steps as $step) {
            $migration->markStepCompleted($step['name']);
            if ($step['name'] === $last) {
                break;
            }
        }

        return $migration->fresh();
    }
}
