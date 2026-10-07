<?php

namespace App\Jobs;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProblemException;
use App\Exceptions\TaskCancelledException;
use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\TaskLogSink;
use App\Models\User;
use App\Rules\RuleExpectation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A rebuild or an archive deploy of a project that exists, or a git pull,
 * branch change or revert of its Deploy-managed checkout and the rebuild
 * after it: behind the 202 POST /projects/{username}/rebuild, /deploy-archive
 * and the three /git endpoints answer with.
 */
class RebuildProject implements ShouldQueue
{
    use AttachTask;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    /**
     * @param array<string, list<array<string, mixed>>>|null $stages
     * @param array<string, string>|null $git a git action's input: `path` and `strategy`, `branch` or `ref`
     */
    public function __construct(
        public string $username,
        public string $action = ProjectRebuild::REBUILD,
        public ?string $zipPath = null,
        public ?array $stages = null,
        public ?string $recipe = null,
        public ?array $git = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProjectRebuild $rebuild): void
    {
        $this->runTask(function () use ($rebuild): void {
            $user = User::findByUsername($this->username);
            if ($user === null) {
                $this->logTask("Not deploying: project '{$this->username}' was deleted.");
                throw new TaskCancelledException();
            }

            app(DeployPlanContext::class)->set($this->stages !== null ? DeployPlan::fromArray($this->stages) : null);
            // Set even when null: the worker's singleton outlives the previous job.
            app(RecipeChoiceContext::class)->set($this->recipe);

            $sink = new TaskLogSink($this->task());
            DeployLogger::streamTo($sink);
            try {
                // Cancelled while it waited: stop before touching the deploy log.
                $this->throwIfCancelled();
                $logger = $rebuild->openLog($user, $this->action);
                $this->recordDeployId($logger);
                $result = $rebuild->run($user, $this->action, $logger, $this->zipPath, $this->git ?? []);
            } catch (DeployAlreadyRunningException $e) {
                // A deploy started without a task (the CLI, a push) took the lock since this was queued.
                throw $this->failure(ProblemException::one('deploy', 'deploy_already_running', $e->getMessage()));
            } catch (ValidationException $e) {
                throw $this->failure($e);
            } finally {
                $sink->flush();
                DeployLogger::stopStreaming();
            }

            $user->refresh();
            $this->recordOutcome($user, $result);
        });
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && !$task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Rebuild job failed'));
        }
    }

    /**
     * The deploy this task runs. The log it resumes may have been started by
     * the create, long before this task: the reconciler and a cancel match the
     * task to its log by this id, not by when the log started.
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
     * The failure on the task as the 422 used to say it: `problems` with the
     * code, stage and deploy log offset, and the message as `details.error`.
     */
    private function failure(ValidationException $e): Throwable
    {
        $problems = $e instanceof ProblemException && $e->problems !== []
            ? $e->problems
            : RuleExpectation::problems($e->validator);

        $task = $this->task();
        if ($task !== null && $problems !== []) {
            $task->details = array_merge($task->details ?? [], ['problems' => $problems]);
            $task->save();
        }

        if (($problems[0]['code'] ?? null) === 'deploy_cancelled') {
            return new TaskCancelledException();
        }

        $messages = array_merge(...array_values($e->errors()));

        return new RuntimeException($messages !== [] ? implode(' | ', $messages) : $e->getMessage(), 0, $e);
    }

    /**
     * @param array<string, mixed> $result what the run reported, e.g. the commit a git action left
     */
    private function recordOutcome(User $user, array $result): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        $details = array_merge($task->details ?? [], $result);
        $status = $user->getDeploymentStatus();
        if ($status === 'partial') {
            $details['deployment_status'] = 'partial';
            $warnings = $user->getDeploymentWarnings();
            if ($warnings !== []) {
                $details['deployment_warnings'] = $warnings;
            }
        } else {
            $details['deployment_status'] = $status === 'unknown' ? 'success' : $status;
        }

        $task->details = $details;
        $task->save();
    }
}
