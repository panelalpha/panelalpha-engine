<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use RuntimeException;
use Throwable;

/**
 * Database / files / finalize loops matching the panel's
 * migrateDatabaseUsingPlugin, migrateFilesUsingPlugin, and
 * finalizeMigrationUsingPlugin predicates.
 */
final class MigrationTransfer
{
    /**
     * Probe destination site HTTP status. Injected in tests; production asks
     * {@see DestinationProbe}, which sends the name to this engine, not to
     * wherever its DNS still points.
     *
     * @var callable(string): int|null
     */
    private $httpStatusProbe;

    /**
     * Remove destination .htaccess when HTTP status changes after transfer.
     *
     * @var callable(string): void|null
     */
    private $htaccessRemover;

    /**
     * Receives the batch report after batches, at most once per
     * PROGRESS_INTERVAL_SECONDS except on phase change or the final batch.
     *
     * @var callable(TransferProgress): void|null
     */
    private $onProgress;

    private const PROGRESS_INTERVAL_SECONDS = 2.0;

    private float $lastProgressAt = 0.0;

    private ?string $lastProgressPhase = null;

    /**
     * The /remote-sync/status read when the loop began, for its table and file counts.
     *
     * @var array<array-key, mixed>
     */
    private array $status = [];

    /**
     * @param  callable(string): int|null  $httpStatusProbe
     * @param  callable(string): void|null  $htaccessRemover
     * @param  callable(TransferProgress): void|null  $onProgress
     */
    public function __construct(
        private SourceWordpressClient $client,
        ?callable $httpStatusProbe = null,
        ?callable $htaccessRemover = null,
        ?callable $onProgress = null,
    ) {
        $this->httpStatusProbe = $httpStatusProbe;
        $this->htaccessRemover = $htaccessRemover;
        $this->onProgress = $onProgress;
    }

    /**
     * Early exit: status === completed OR phase === files.
     * Batch loop ends when boolean finalized OR next_phase === files, or when
     * the budget is spent: false then, and the next run continues.
     * After a network error, success only if status === completed.
     */
    public function migrateDatabase(string $migrationId, ?BatchBudget $budget = null): bool
    {
        $status = $this->readStatus($migrationId);
        if (($status['status'] ?? null) === 'completed' || ($status['phase'] ?? null) === 'files') {
            return true;
        }

        return $this->runBatches(
            $migrationId,
            $budget,
            static fn (array $result): bool => ($result['finalized'] ?? false) || ($result['next_phase'] ?? null) === 'files',
        );
    }

    /**
     * Ends when batch has boolean finalized, or when the budget is spent.
     * After a network error, success only if status === completed.
     */
    public function migrateFiles(string $migrationId, ?BatchBudget $budget = null): bool
    {
        $status = $this->readStatus($migrationId);
        if (($status['status'] ?? null) === 'completed') {
            return true;
        }
        if (($status['phase'] ?? null) === 'database') {
            return $this->migrateDatabase($migrationId, $budget);
        }

        return $this->runBatches(
            $migrationId,
            $budget,
            static fn (array $result): bool => (bool) ($result['finalized'] ?? false),
        );
    }

    /**
     * @param  callable(array<string, mixed>): bool  $isCompleted
     */
    private function runBatches(string $migrationId, ?BatchBudget $budget, callable $isCompleted): bool
    {
        $budget ??= new BatchBudget(PHP_INT_MAX, PHP_INT_MAX);
        do {
            $budget->spend();
            try {
                $result = $this->client->processBatch($migrationId);
            } catch (Throwable $exception) {
                $after = $this->client->migrationStatus($migrationId);
                if (($after['status'] ?? null) === 'completed') {
                    return true;
                }
                throw $exception;
            }
            $completed = $isCompleted($result);
            $this->reportProgress($result, $completed);
        } while (! $completed && ! $budget->exhausted());

        return $completed;
    }

    /**
     * Confirm transfer finished, then move .htaccess. If the destination HTTP
     * status changes, remove the file and append a warning on the migration step log.
     */
    public function finalize(WordpressMigration $migration, string $migrationId, string $destinationSiteUrl): void
    {
        $status = $this->client->migrationStatus($migrationId);

        if (($status['status'] ?? null) === 'completed') {
            $this->syncHtaccess($migration, $migrationId, $destinationSiteUrl);

            return;
        }

        if (($status['phase'] ?? null) === 'database') {
            $this->migrateDatabase($migrationId);
            $this->syncHtaccess($migration, $migrationId, $destinationSiteUrl);

            return;
        }

        if (($status['phase'] ?? null) === 'files') {
            $this->migrateFiles($migrationId);
            $this->syncHtaccess($migration, $migrationId, $destinationSiteUrl);

            return;
        }

        throw new RuntimeException('Migration was interrupted');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readStatus(string $migrationId): array
    {
        $this->status = $this->client->migrationStatus($migrationId);

        return $this->status;
    }

    /**
     * @param  array{current_phase: ?string, current_phase_percent: float, current_item: ?string, sent_chunks?: int, total_chunks?: int}  $result
     */
    private function reportProgress(array $result, bool $force): void
    {
        if ($this->onProgress === null) {
            return;
        }

        $phase = $result['current_phase'];
        $now = microtime(true);
        if (! $force && $phase === $this->lastProgressPhase
            && $now - $this->lastProgressAt < self::PROGRESS_INTERVAL_SECONDS) {
            return;
        }

        $this->lastProgressAt = $now;
        $this->lastProgressPhase = $phase;
        ($this->onProgress)(TransferProgress::fromBatch($result, $this->status));
    }

    private function syncHtaccess(
        WordpressMigration $migration,
        string $migrationId,
        string $destinationSiteUrl,
    ): void {
        $probe = $this->httpStatusProbe ?? (new DestinationProbe)->status(...);
        $statusBefore = $probe($destinationSiteUrl);

        $transferred = $this->client->transferHtaccess($migrationId);
        if ($transferred === false) {
            return;
        }

        $statusAfter = $probe($destinationSiteUrl);
        if ($statusBefore === $statusAfter) {
            return;
        }

        $remover = $this->htaccessRemover;
        if ($remover !== null) {
            $remover($destinationSiteUrl);
        }

        $migration->appendStepLog(
            WordpressMigration::STEP_FINALIZE,
            WordpressMigration::STEP_STATUS_COMPLETED,
            sprintf(
                'Skipped keeping transferred .htaccess: HTTP status changed from %d to %d',
                $statusBefore,
                $statusAfter,
            ),
        );
    }
}
