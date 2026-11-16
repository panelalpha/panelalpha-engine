<?php

namespace App\Jobs;

use App\Jobs\Concerns\AttachTask;
use App\Lib\Project\ProjectCopier;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * The copy behind a create with a `project` source: the account rows were
 * saved by the request, {@see ProjectCopier::copyInto()} copies into them.
 */
class CopyProject implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(
        public int $sourceId,
        public int $copyId,
        public bool $copyDatabases = true,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProjectCopier $copier): void
    {
        $this->runTask(fn () => $copier->copyInto($this->copyId, $this->sourceId, $this->copyDatabases, fn (string $line) => $this->logTask($line)));
    }

    protected function cancelledBeforeWork(): void
    {
        app(ProjectCopier::class)->discard($this->copyId);
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && ! $task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Copy job failed'));
        }
        app(ProjectCopier::class)->discard($this->copyId);
    }
}
