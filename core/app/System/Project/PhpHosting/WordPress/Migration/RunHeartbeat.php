<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;

/**
 * Keeps one run's claim on its migration alive while it waits on the source
 * site. The heartbeat is written at most every INTERVAL_SECONDS; once another
 * run has taken the record over, the run is told to stop and stays stopped.
 */
final class RunHeartbeat
{
    public const INTERVAL_SECONDS = 30;

    private int $lastBeat;

    private bool $lost = false;

    public function __construct(
        private readonly WordpressMigration $migration,
        private readonly string $runId,
    ) {
        $this->lastBeat = now()->getTimestamp();
    }

    /** False once the run no longer holds the migration. */
    public function keepAlive(): bool
    {
        if (! $this->lost && $this->due()) {
            $this->beat();
        }

        return ! $this->lost;
    }

    private function due(): bool
    {
        return now()->getTimestamp() - $this->lastBeat >= self::INTERVAL_SECONDS;
    }

    private function beat(): void
    {
        $this->lastBeat = now()->getTimestamp();
        $this->lost = ! $this->migration->refreshHeartbeat($this->runId);
    }
}
