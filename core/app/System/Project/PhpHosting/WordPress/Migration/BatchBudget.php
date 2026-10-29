<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Closure;

/**
 * How much of a transfer one run may do: a number of process-batch calls and
 * a time limit, whichever comes first, cut short when the import is asked to stop.
 */
final class BatchBudget
{
    public const BATCHES = 100;

    public const SECONDS = 900;

    private int $used = 0;

    private ?float $startedAt = null;

    /**
     * @param  (Closure(): bool)|null  $stopRequested
     */
    public function __construct(
        private int $batches = self::BATCHES,
        private int $seconds = self::SECONDS,
        private ?Closure $stopRequested = null,
    ) {}

    public function spend(): void
    {
        $this->startedAt ??= microtime(true);
        $this->used++;
    }

    public function exhausted(): bool
    {
        return $this->used >= $this->batches
            || ($this->startedAt !== null && microtime(true) - $this->startedAt >= $this->seconds)
            || ($this->stopRequested !== null && ($this->stopRequested)());
    }
}
