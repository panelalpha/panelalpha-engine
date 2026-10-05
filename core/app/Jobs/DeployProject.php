<?php

namespace App\Jobs;

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

    public int $timeout = 7200;

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
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $user = User::findByUsernameOrFail($this->username);

            $plan = $this->stages !== null ? DeployPlan::fromArray($this->stages) : null;
            app(DeployPlanContext::class)->set($plan);
            // Set even when null: the worker's singleton outlives the previous job.
            app(RecipeChoiceContext::class)->set($this->recipe);

            $sink = new TaskLogSink($this->task());
            DeployLogger::streamTo($sink);

            $logger = null;
            try {
                if ($user->hasGitProject() || $user->getTemplate() === 'dind') {
                    $logger = DeployLogger::startSafely($user->username);
                    if ($logger !== null) {
                        $gitRepo = $user->getGitRepo();
                        $logger->info($gitRepo
                            ? 'Deploy started (source: git, repo: ' . GitUrl::sanitize($gitRepo) . ')'
                            : 'Deploy started (source: dind template)');
                        CreateInspection::fromArray($this->inspection)?->writeTo($logger);
                    }
                }

                // The rollback deletes the deploy log; keep its tail on the task.
                $tailKept = false;
                $keepTail = function (DeployLogger $failed) use ($sink, &$tailKept): void {
                    $sink->flush();
                    $tailKept = DeployLogTail::preserve($this->task(), $failed);
                };

                try {
                    $user->project()->runDeployment($logger, $keepTail);
                } catch (ValidationException $e) {
                    // Pipeline already rolled the account back and finished the
                    // deploy log. Surface the message on the task and stop.
                    $summary = $this->validationSummary($e);
                    throw new RuntimeException($tailKept ? DeployLogTail::retarget($summary) : $summary, 0, $e);
                }

                $user->refresh();
                $this->recordOutcome($user);
            } finally {
                $sink->flush();
                DeployLogger::stopStreaming();
            }
        });
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
        } elseif ($user->getTemplate() === 'dind' && !$user->hasGitProject() && $status === 'unknown') {
            // Zip/placeholder path: pipeline stopped after preparing.
            $details['waiting_for_files'] = true;
        } else {
            $details['deployment_status'] = $status === 'unknown' ? 'success' : $status;
        }

        $task->details = $details;
        $task->save();
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
