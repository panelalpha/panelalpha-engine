<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateDatabaseStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateFilesStep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RoutedSourceHttpClient;

/**
 * The database and files steps: a bounded share of batches per run, the
 * step's own progress after each, and completion once the phase is done.
 */
class WordpressMigrationTransferStepsTest extends WordpressMigrationTestCase
{
    private const STATUS_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/status/mig-t';

    private const BATCH_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function steps(): array
    {
        return [
            'database' => [MigrateDatabaseStep::class, WordpressMigration::STEP_MIGRATE_DATABASE, 'database'],
            'files' => [MigrateFilesStep::class, WordpressMigration::STEP_MIGRATE_FILES, 'files'],
        ];
    }

    /**
     * @param  class-string<BatchedStep>  $class
     */
    #[DataProvider('steps')]
    public function test_a_run_spends_its_batches_and_stores_the_steps_progress(string $class, string $name, string $phase): void
    {
        $migration = $this->transferring();
        $sent = 0;
        $http = $this->batches($phase, 5, $sent);
        $step = new $class(new SourceConnection(fn () => $http), 2);

        $this->assertSame($name, $step->name());
        $step->run($migration);

        $this->assertSame(2, $sent);
        $fresh = $migration->fresh();
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $fresh->stepStatus($name));
        $this->assertSame($name, $fresh->progress['step']);
        $this->assertSame(20, $fresh->progress['phase_percent']);
        $this->assertSame('item-1', $fresh->progress['item']);

        $step->run($fresh);
        $step->run($migration->fresh());

        $this->assertSame(5, $sent);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus($name));
        $this->assertSame(100, $migration->fresh()->progress['phase_percent']);
        $this->assertSame('item-5', $migration->fresh()->progress['item']);
    }

    /**
     * @param  class-string<BatchedStep>  $class
     */
    #[DataProvider('steps')]
    public function test_a_phase_the_source_reports_completed_completes_without_a_batch(string $class, string $name, string $phase): void
    {
        $migration = $this->transferring();
        $http = (new RoutedSourceHttpClient)->on('GET', self::STATUS_URL, new SourceHttpResponse(
            json_encode(['success' => true, 'status' => ['status' => 'completed', 'phase' => 'completed']], JSON_THROW_ON_ERROR),
            200,
        ));

        (new $class(new SourceConnection(fn () => $http)))->run($migration);

        $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->fresh()->stepStatus($name));
    }

    public function test_the_database_step_needs_the_plugin_migration_id(): void
    {
        $this->expectExceptionObject(new RuntimeException('Cannot migrate database: plugin_migration_id is missing'));

        (new MigrateDatabaseStep(new SourceConnection(fn () => new RoutedSourceHttpClient)))->run($this->migration());
    }

    public function test_the_files_step_needs_the_plugin_migration_id(): void
    {
        $this->expectExceptionObject(new RuntimeException('Cannot migrate files: plugin_migration_id is missing'));

        (new MigrateFilesStep(new SourceConnection(fn () => new RoutedSourceHttpClient)))->run($this->migration());
    }

    public function test_a_cancel_request_ends_the_run_after_the_batch_it_is_in(): void
    {
        $migration = $this->transferring();
        $sent = 0;
        $http = $this->batches('database', 50, $sent, $migration->requestCancel(...));

        (new MigrateDatabaseStep(new SourceConnection(fn () => $http)))->run($migration);

        $this->assertSame(1, $sent);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_MIGRATE_DATABASE));
    }

    private function transferring(): WordpressMigration
    {
        $migration = $this->migration();
        $migration->storePluginMigrationId('mig-t');
        $migration->storeSourceToken(self::SOURCE_TOKEN);
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    private function migration(): WordpressMigration
    {
        return WordpressMigration::start('https://example.com', 'admin', 'secret');
    }

    private function batches(string $phase, int $total, int &$sent, ?callable $onBatch = null): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient)
            ->requiringToken(self::SOURCE_TOKEN)
            ->on('GET', self::STATUS_URL, new SourceHttpResponse(
                json_encode(['success' => true, 'status' => ['status' => 'in_progress', 'phase' => 'ready', 'backup_running' => false]], JSON_THROW_ON_ERROR),
                200,
            ))
            ->on('POST', self::BATCH_URL, function () use (&$sent, $total, $phase, $onBatch): SourceHttpResponse {
                $sent++;
                if ($onBatch !== null) {
                    $onBatch();
                }

                return new SourceHttpResponse(json_encode([
                    'finalized' => $sent >= $total,
                    'phase' => $phase,
                    'progress' => ['percentage' => 100 * $sent / $total, 'current_item' => 'item-'.$sent],
                ], JSON_THROW_ON_ERROR), 200);
            });
    }
}
