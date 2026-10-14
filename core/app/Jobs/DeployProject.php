<?php

namespace App\Jobs;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Exceptions\TaskCancelledException;
use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Project\CreateInspection;
use App\Lib\Task\DeployLogTail;
use App\Lib\Task\TaskLogSink;
use App\Models\User;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DeployProject implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    /** The project's row; null on a job queued before it was recorded. */
    public ?int $userId = null;

    /**
     * @param array<string, list<array<string, mixed>>>|null $stages
     * @param ?array<string, mixed> $inspection what the create's inspection
     *        found, written under the log's first line
     */
    public function __construct(
        public string $username,
        public ?array $stages = null,
        public ?string $recipe = null,
        public ?array $inspection = null,
        ?int $userId = null,
    ) {
        $this->userId = $userId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $user = $this->project();
            $this->bindDeployContext();

            $sink = new TaskLogSink($this->task());
            DeployLogger::streamTo($sink);

            try {
                $logger = $this->startDeployLog($user);
                $this->recordDeployId($logger);
                $this->deploy($user, $logger, $sink);

                $user->refresh();
                $this->recordOutcome($user);
            } finally {
                $sink->flush();
                DeployLogger::stopStreaming();
            }
        });
    }

    protected function runDeployment(User $user, ?DeployLogger $logger, callable $keepTail): void
    {
        $user->project()->runDeployment($logger, $keepTail);
    }

    /**
     * By row id once the job carries one: a project deleted and created again
     * under the same name is not the one this deploy was queued for.
     */
    private function project(): User
    {
        if ($this->userId === null) {
            return User::findByUsernameOrFail($this->username);
        }

        return User::query()->whereKey($this->userId)->first()
            ?? throw new NotFoundException("Project '{$this->username}' not found.", User::class);
    }

    /**
     * Set even when null: the worker's singletons outlive the previous job.
     */
    private function bindDeployContext(): void
    {
        app(DeployPlanContext::class)->set($this->stages !== null ? DeployPlan::fromArray($this->stages) : null);
        app(RecipeChoiceContext::class)->set($this->recipe);
    }

    private function startDeployLog(User $user): ?DeployLogger
    {
        if (!$user->hasGitProject() && $user->getTemplate() !== 'dind') {
            return null;
        }

        $logger = DeployLogger::startSafely($user->username);
        if ($logger === null) {
            return null;
        }

        $gitRepo = $user->getGitRepo();
        $logger->info($gitRepo
            ? 'Deploy started (source: git, repo: ' . GitUrl::sanitize($gitRepo) . ')'
            : 'Deploy started (source: dind template)');
        CreateInspection::fromArray($this->inspection)?->writeTo($logger);

        return $logger;
    }

    /**
     * The deploy this task runs, so the reconciler, a cancel and an API client
     * can tie the create to its log as they do a rebuild.
     */
    private function recordDeployId(?DeployLogger $logger): void
    {
        $task = $this->task();
        if ($task === null || $logger === null) {
            return;
        }
        $task->details = array_merge($task->details ?? [], ['deploy_id' => $logger->getDeployId()]);
        $task->save();
    }

    /**
     * A failed pipeline has already rolled the account back and deleted the
     * deploy log, so the log's tail is kept on the task and only the message is raised.
     */
    private function deploy(User $user, ?DeployLogger $logger, TaskLogSink $sink): void
    {
        $tailKept = false;
        $keepTail = function (DeployLogger $failed) use ($sink, &$tailKept): void {
            $sink->flush();
            $tailKept = DeployLogTail::preserve($this->task(), $failed);
        };

        try {
            $this->runDeployment($user, $logger, $keepTail);
        } catch (ValidationException $e) {
            if ($this->isCancellation($e)) {
                throw new TaskCancelledException();
            }

            $summary = $this->validationSummary($e);
            throw new RuntimeException($tailKept ? DeployLogTail::retarget($summary) : $summary, 0, $e);
        }
    }

    /**
     * A cancel is an expected outcome: no failed job, no ERROR line.
     */
    private function isCancellation(ValidationException $e): bool
    {
        return $e instanceof ProblemException && ($e->problems[0]['code'] ?? null) === 'deploy_cancelled';
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && !$task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Deploy job failed'));
        }
    }

    private function recordOutcome(User $user): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        $details = $task->details ?? [];
        $status = $user->getDeploymentStatus();

        if ($status === 'partial') {
            $details['deployment_status'] = 'partial';
            $warnings = $user->getDeploymentWarnings();
            if ($warnings !== []) {
                $details['deployment_warnings'] = $warnings;
            }
        } elseif ($this->stoppedBeforeFilesArrived($user, $status)) {
            $details['waiting_for_files'] = true;
        } else {
            $details['deployment_status'] = $status === 'unknown' ? 'success' : $status;
        }

        $task->details = $details;
        $task->save();
    }

    /**
     * The zip/placeholder path: the pipeline stops after preparing and waits for an upload.
     */
    private function stoppedBeforeFilesArrived(User $user, string $status): bool
    {
        return $user->getTemplate() === 'dind' && !$user->hasGitProject() && $status === 'unknown';
    }

    private function validationSummary(ValidationException $e): string
    {
        $messages = $e->errors();
        $flat = [];
        foreach ($messages as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $flat[] = $message;
            }
        }

        return $flat !== [] ? implode(' | ', $flat) : $e->getMessage();
    }
}
