<?php

namespace App\Console\Commands\System;

use App\Support\QueueWorkers;
use Illuminate\Console\Command;

/**
 * Writes the generated `[program:queue]` block from `QUEUE_WORKERS`, so
 * supervisord's own first start has one to `[include]`. Internal plumbing for
 * `entrypoint-core.sh`, run before supervisord exists yet -- not the
 * operator-facing way to change the count; that is `pae configure queue`.
 */
class QueueWorkersSync extends Command
{
    protected $signature = 'system:queue-workers:sync';

    protected $description = 'Write the queue worker count from .env into supervisord (internal; used by the entrypoint)';

    public function handle(): int
    {
        QueueWorkers::apply(QueueWorkers::configured());

        return self::SUCCESS;
    }
}
