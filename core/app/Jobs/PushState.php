<?php

namespace App\Jobs;

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

class PushState implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public string $fromUsername, public string $toUsername)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            (new System())->projects()->pushToApp($this->fromUsername, $this->toUsername);
        });
    }

    public function failed(\Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && !$task->isTerminal()) {
            $this->markFailed($e);
        }

        $from = User::findByUsername($this->fromUsername);
        $to = User::findByUsername($this->toUsername);
        $system = new System();

        if ($to !== null) {
            $this->markPushFailed($to, $e);
            $this->removeIncoming($system, $to);
        }

        $this->resumeAfterCopy($system, $from);
        $this->resumeAfterCopy($system, $to);
    }

    private function markPushFailed(User $to, \Throwable $e): void
    {
        $to->mergeAsyncStatus(['push' => 'failed']);
        $to->setDetails(['error' => $e->getMessage()]);
        $to->save();
    }

    private function removeIncoming(System $system, User $to): void
    {
        try {
            $incoming = rtrim($system->project($to)->homeDirPath(), '/') . '/.incoming';
            $system->runProcess(['sudo', 'rm', '-rf', $incoming]);
        } catch (\Throwable $cleanup) {
            Log::warning('PushState failed cleanup could not remove .incoming', [
                'target'    => $this->toUsername,
                'exception' => $cleanup->getMessage(),
            ]);
        }
    }

    private function resumeAfterCopy(System $system, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        try {
            $system->project($user)->copyVolumesForClone()?->resumeAfterCopy();
        } catch (\Throwable $ignored) {
        }
    }
}
