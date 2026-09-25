<?php

namespace App\Console\Commands\System;

use App\Support\QueueWorkers;
use Illuminate\Console\Command;

/**
 * Writes the queue worker services from `QUEUE_WORKERS`, so s6-svscan's first
 * scan finds them. Internal plumbing for `entrypoint-core.sh`, run before
 * s6-svscan exists -- not the operator-facing way to change the count; that
 * is `pae configure queue`. Leaves `.env-core` alone, so an empty value keeps
 * meaning "the default for this host".
 */
class QueueWorkersSync extends Command
{
    protected $signature = 'system:queue-workers:sync';

    protected $description = 'Write the queue worker services from .env for s6 (internal; used by the entrypoint)';

    public function handle(): int
    {
        QueueWorkers::writeServices(QueueWorkers::configured(), QueueWorkers::SCAN_DIR);

        return self::SUCCESS;
    }
}
