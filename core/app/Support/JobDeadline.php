<?php

namespace App\Support;

/**
 * The shared work window of a backup or restore job's forward steps; the rest of
 * the job's budget is the cleanup reserve.
 */
final class JobDeadline
{
    /** What a step gets once the window is spent, so it fails rather than running with no timeout. */
    private const SPENT_STEP_SECONDS = 1;

    /** @param float $expiresAt the instant the shared work window ends, as microtime(true) */
    public function __construct(
        private readonly float $expiresAt,
    ) {}

    public static function startingNow(): self
    {
        return new self(microtime(true) + JobBudget::COMMAND_SECONDS);
    }

    /**
     * Seconds the next step may take: what is left, never more than one command's cap.
     */
    public function stepSeconds(): int
    {
        return max(self::SPENT_STEP_SECONDS, min(JobBudget::COMMAND_SECONDS, (int) ceil($this->expiresAt - microtime(true))));
    }

    public function expired(): bool
    {
        return $this->expiresAt <= microtime(true);
    }

    /** A step may only start while the window has time left. */
    public function assertTimeLeft(): void
    {
        if ($this->expired()) {
            throw new \RuntimeException('The backup or restore ran out of its time budget');
        }
    }
}
