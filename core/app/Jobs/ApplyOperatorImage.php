<?php

namespace App\Jobs;

use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Lib\Task\TaskOutputLog;
use App\Models\OperatorImage;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * One operation on an operator image, as the task the API answered with.
 * The build or pull output goes to the task's log as it arrives.
 */
class ApplyOperatorImage implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PUBLISH = 'publish';

    public const REFRESH = 'refresh';

    public const REMOVE = 'remove';

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public int $imageId, public string $action)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $image = OperatorImage::query()->find($this->imageId);
            if ($image === null) {
                return;
            }
            $log = new TaskOutputLog($this->logTask(...));
            try {
                $summary = $this->apply(OperatorImages::make(), $image, $log);
            } finally {
                $log->flush();
            }
            $this->logTask($summary);
        });
    }

    public function failed(?Throwable $e): void
    {
        $reason = $e ?? new RuntimeException('Operator image job failed');
        OperatorImages::abandon($this->imageId, $this->action, $reason->getMessage());
        $task = $this->task();
        if ($task !== null && ! $task->isTerminal()) {
            $this->markFailed($reason);
        }
    }

    /**
     * @param  callable(string): void  $output
     */
    private function apply(OperatorImages $images, OperatorImage $image, callable $output): string
    {
        return match ($this->action) {
            self::REMOVE => "{$image->ref}: ".$images->remove($image),
            self::REFRESH => $images->refresh($image, $output)
                ? "{$image->ref} refreshed in the cache registry."
                : "{$image->ref} is not on the host any more; the cache registry keeps the copy it has.",
            default => $this->published($images, $image, $output),
        };
    }

    /**
     * @param  callable(string): void  $output
     */
    private function published(OperatorImages $images, OperatorImage $image, callable $output): string
    {
        $images->publish($image, false, $output);

        return "{$image->ref} is in the cache registry as {$image->digest}.";
    }
}
