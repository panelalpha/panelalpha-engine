<?php

namespace App\Jobs;

use App\Models\WordpressMigration;
use App\Support\JobBudget;
use App\System\Project\PhpHosting\WordPress\Migration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue adapter for a WordPress migration. The payload is the record id.
 * Each job makes one bounded run of the migration module and queues the next
 * one while work remains, so no job outlives the queue's retry_after. A
 * cancel that finds the record held by another run waits for that run to
 * hand over, or for its heartbeat to go stale.
 */
class MigrateWordpress implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Seconds a cancel waits before it asks for the record again. */
    public const BUSY_RETRY_SECONDS = 60;

    /** Enough waits to outlast a dead run's heartbeat going stale. */
    public int $tries = 20;

    /** A failed step fails the job at once; only a wait for the record is retried. */
    public int $maxExceptions = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public int $migrationId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $migration = WordpressMigration::query()->find($this->migrationId);
        if ($migration === null) {
            return;
        }

        $slice = app(Migration::class)->advanceOnce($migration);
        if ($slice === MigrationSlice::More) {
            self::dispatch($this->migrationId);
        }
        if ($slice === MigrationSlice::Busy && $migration->cancelRequested()) {
            $this->release(self::BUSY_RETRY_SECONDS);
        }
    }
}
