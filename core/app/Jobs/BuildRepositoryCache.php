<?php

namespace App\Jobs;

use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\CacheManager\BuildCacheProducer;
use App\Lib\Deploy\CacheManager\BuildCacheRefused;
use App\Lib\Task\TaskOutputLog;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Build a repository's caches, as the task the API answered with. Each
 * build's progress goes to the task's log as it happens.
 */
class BuildRepositoryCache implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public string $repository, public ?string $branch)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $log = new TaskOutputLog($this->logTask(...));
            try {
                $published = BuildCacheProducer::make(static fn (string $line) => $log($line."\n"))
                    ->build($this->repository, $this->branch);
            } catch (BuildCacheRefused $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            } finally {
                $log->flush();
            }
            if ($published === []) {
                throw new RuntimeException('No build of this repository produced a cache; see the log above.');
            }
            $this->logTask('Published '.implode(', ', $published).'.');
        });
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && ! $task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Build cache job failed'));
        }
    }
}
