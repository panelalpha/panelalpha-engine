<?php

namespace App\Support;

/**
 * The one budget a queued job runs under, and the caps around it.
 *
 * Laravel SIGKILLs a worker when its job's `$timeout` fires, so an inner
 * command must be capped below it to fail through its own cleanup. The worker's
 * `--timeout` sits above the job's, and the queue's `retry_after` above that,
 * so a running job is never handed to a second worker.
 */
final class JobBudget
{
    /** Every queued job's `$timeout`, and the window a cancelled job is still stopping. */
    public const SECONDS = 7200;

    /** An inner command's own timeout, below the job's. */
    public const COMMAND_SECONDS = 6600;

    /** The worker's `--timeout`, above the job's so it never kills a live job. */
    public const WORKER_SECONDS = 7800;

    /** The queue's `retry_after`, above the worker's `--timeout`. */
    public const RETRY_AFTER_SECONDS = 7900;
}
