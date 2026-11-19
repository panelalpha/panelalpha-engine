<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Database / files / finalize loops matching the panel's migrateDatabaseUsingPlugin,
 * migrateFilesUsingPlugin, and finalizeMigrationUsingPlugin predicates.
 */
final class MigrationTransfer
{
    /**
     * Probe destination site HTTP status; production asks {@see DestinationProbe},
     * which sends the name to this engine, not to wherever its DNS still points.
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

    /**
     * Plugin statuses (v1.1.4) meaning the transfer is not finished and nobody has to be waited for.
     */
    private const UNFINISHED_STATUSES = ['pending', 'in_progress', 'interrupted', 'idle'];

    private const WAIT_POLL_SECONDS = 5;

    /**
     * Engine's own "run is dead" window; a source backup idle longer than this is not waited for.
     */
    private const SOURCE_STALE_MINUTES = 15;

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
     * Done at once when status === completed; batches end on finalized, next_phase === files or a
     * spent budget (false, the next run continues). After a network error, success only if status === completed.
     */
    public function migrateDatabase(string $migrationId, ?BatchBudget $budget = null): bool
    {
        $status = $this->readStatus($migrationId);
        if (($status['status'] ?? null) === 'completed') {
            return true;
        }

        return $this->runBatches(
            $migrationId,
            $budget,
            static fn (array $result): bool => ($result['finalized'] ?? false) || ($result['next_phase'] ?? null) === 'files',
        );
    }

    /**
     * Ends when batch has boolean finalized, or when the budget is spent. The plugin picks the part to send
     * (database or files) itself, so a files step that starts early still finishes.
     * After a network error, success only if status === completed.
     */
    public function migrateFiles(string $migrationId, ?BatchBudget $budget = null): bool
    {
        $status = $this->readStatus($migrationId);
        if (($status['status'] ?? null) === 'completed') {
            return true;
        }

        return $this->runBatches(
            $migrationId,
            $budget,
            static fn (array $result): bool => (bool) ($result['finalized'] ?? false),
        );
    }

    /**
     * Without a budget a run gets the standard one, never an unbounded loop.
     * With a stall guard, a stalled source fails the run instead of spending the whole budget.
     *
     * @param  callable(array<string, mixed>): bool  $isCompleted
     */
    private function runBatches(
        string $migrationId,
        ?BatchBudget $budget,
        callable $isCompleted,
        ?BatchStallGuard $stallGuard = null,
    ): bool {
        $budget ??= new BatchBudget;
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
            if ($stallGuard?->stalled($result, $completed) === true) {
                throw self::noNewChunks($result);
            }
        } while (! $completed && ! $budget->exhausted());

        return $completed;
    }

    /**
     * Confirm the transfer finished, then move .htaccess, removing it with a step-log warning if the destination HTTP
     * status changes. A transfer still running goes on within the budget: false until it has finished.
     */
    public function finalize(
        WordpressMigration $migration,
        string $migrationId,
        string $destinationSiteUrl,
        ?BatchBudget $budget = null,
    ): bool {
        if (! $this->transferFinished($migrationId, $budget)) {
            return false;
        }

        $this->syncHtaccess($migration, $migrationId, $destinationSiteUrl);

        return true;
    }

    /**
     * Decides on the plugin's `status`. completed: done. not_found, an unknown or a missing status: fail.
     * An unfinished status with backup_running not true: continue with batches until finalized, within the
     * budget. backup_running true (another client sends): wait and re-read, within the budget, unless the
     * source has been idle for SOURCE_STALE_MINUTES. `phase` is only used in messages.
     */
    private function transferFinished(string $migrationId, ?BatchBudget $budget): bool
    {
        $budget ??= new BatchBudget;
        while (true) {
            $status = $this->readStatus($migrationId);
            if (($status['status'] ?? null) === 'completed') {
                return true;
            }
            self::assertUnfinished($status);
            if (($status['backup_running'] ?? false) !== true) {
                return $this->sendRemainingBatches($migrationId, $budget);
            }
            if (! $this->waitedForOtherSender($status, $budget)) {
                return false;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $status
     */
    private static function assertUnfinished(array $status): void
    {
        $state = $status['status'] ?? null;
        if ($state === 'not_found') {
            throw self::interrupted($status, 'the source has no record of this migration');
        }
        if (! is_string($state) || ! in_array($state, self::UNFINISHED_STATUSES, true)) {
            throw self::interrupted($status, 'the source reports a status this engine does not handle');
        }
    }

    private function sendRemainingBatches(string $migrationId, BatchBudget $budget): bool
    {
        return $this->runBatches(
            $migrationId,
            $budget,
            static fn (array $result): bool => (bool) ($result['finalized'] ?? false),
            new BatchStallGuard,
        );
    }

    /**
     * One poll interval spent waiting for the client whose backup is running; false once the budget is spent.
     *
     * @param  array<array-key, mixed>  $status
     */
    private function waitedForOtherSender(array $status, BatchBudget $budget): bool
    {
        if (self::sourceIdleTooLong($status)) {
            throw self::interrupted($status, sprintf(
                'the source has made no progress for over %d minutes',
                self::SOURCE_STALE_MINUTES,
            ));
        }
        if ($budget->exhausted()) {
            return false;
        }
        $budget->spend();
        Sleep::sleep(self::WAIT_POLL_SECONDS);

        return true;
    }

    /**
     * @param  array{current_phase: ?string, sent_chunks: int, total_chunks: int}  $result
     */
    private static function noNewChunks(array $result): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Migration was interrupted: the source sent no new chunks in %d batches (phase %s, %d/%d chunks)',
            BatchStallGuard::BATCHES,
            self::quoted($result['current_phase']),
            $result['sent_chunks'],
            $result['total_chunks'],
        ));
    }

    /**
     * One message for every "interrupted" failure, showing what the source reported.
     *
     * @param  array<array-key, mixed>  $status
     */
    private static function interrupted(array $status, string $reason): RuntimeException
    {
        $backupRunning = $status['backup_running'] ?? null;
        $lastActivity = $status['last_activity'] ?? null;

        return new RuntimeException(sprintf(
            'Migration was interrupted: %s (status %s, phase %s, backup_running %s, last activity %s)',
            $reason,
            self::quoted($status['status'] ?? null),
            self::quoted($status['phase'] ?? null),
            is_bool($backupRunning) ? var_export($backupRunning, true) : 'unknown',
            is_string($lastActivity) && $lastActivity !== '' ? Str::limit($lastActivity, 64) : 'unknown',
        ));
    }

    /**
     * True when last_activity is missing, cannot be read, or is older than SOURCE_STALE_MINUTES.
     *
     * @param  array<array-key, mixed>  $status
     */
    private static function sourceIdleTooLong(array $status): bool
    {
        $lastActivity = $status['last_activity'] ?? null;
        if (! is_string($lastActivity) || $lastActivity === '') {
            return true;
        }

        try {
            $at = Carbon::parse($lastActivity);
        } catch (Throwable) {
            return true;
        }

        return $at->lt(now()->subMinutes(self::SOURCE_STALE_MINUTES));
    }

    /**
     * The value in double quotes (cut to 64 characters) when it is a non-empty string, else the bare word missing.
     */
    private static function quoted(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? '"'.Str::limit($value, 64).'"'
            : 'missing';
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

        $this->logHtaccessSkipped($migration, $statusBefore, $statusAfter);
    }

    private function logHtaccessSkipped(WordpressMigration $migration, ?int $statusBefore, ?int $statusAfter): void
    {
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
