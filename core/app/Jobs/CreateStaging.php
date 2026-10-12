<?php

namespace App\Jobs;

use App\Exceptions\NotFoundException;
use App\Jobs\Concerns\AttachTask;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateStaging implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    /** The destination's row; null on a job queued before it was recorded. */
    public ?int $destId = null;

    public function __construct(public string $destUsername, ?int $destId = null)
    {
        $this->destId = $destId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $dest = $this->destination()
                ?? throw new NotFoundException("Project '{$this->destUsername}' not found.", User::class);
            $source = $dest->liveUser;
            if ($source === null) {
                throw new \RuntimeException("Staging '{$this->destUsername}' has no live parent.");
            }

            (new System())->projects()->copy($source->username, $dest->username);
        });
    }

    /**
     * The task is marked first: it is the only trace of this failure that
     * outlives the destination's deletion below.
     */
    public function failed(\Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && !$task->isTerminal()) {
            $this->markFailed($e);
        }

        $dest = $this->destination();
        if ($dest === null) {
            return;
        }

        $this->markStagingFailed($dest, $e);
        $this->resumeSourceAfterCopy($dest);
        $this->destroyDestination($dest);
    }

    private function markStagingFailed(User $dest, \Throwable $e): void
    {
        try {
            $dest->markStagingFailed($e->getMessage());
        } catch (\Throwable $mark) {
            Log::error('Could not mark staging failed', ['dest' => $this->destUsername, 'exception' => $mark]);
        }
    }

    private function resumeSourceAfterCopy(User $dest): void
    {
        $source = $dest->liveUser;
        if ($source === null) {
            return;
        }

        try {
            (new System())->project($source)->copyVolumesForClone()?->resumeAfterCopy();
        } catch (\Throwable $ignored) {
        }
    }

    private function destroyDestination(User $dest): void
    {
        try {
            $dest->project()->destroy();
        } catch (\Throwable $cleanup) {
            Log::error('CreateStaging cleanup failed', [
                'dest'      => $this->destUsername,
                'exception' => $cleanup,
            ]);
        }
    }

    /**
     * By row id once the job carries one: a project that took the name of a
     * deleted destination is not this copy's to copy into or destroy.
     */
    private function destination(): ?User
    {
        return $this->destId === null
            ? User::findByUsername($this->destUsername)
            : User::query()->whereKey($this->destId)->first();
    }
}
