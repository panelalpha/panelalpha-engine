<?php

namespace App\Jobs;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProblemException;
use App\Exceptions\TaskCancelledException;
use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Project\ApiAccess;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use App\System\Project\Dind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Re-point a container project's environment at the env_vars it now carries,
 * without a rebuild: rewrite the env files, refresh the generated run file's
 * `environment:` and start the stack, which recreates only the services whose
 * configuration changed. Behind the 202 PUT /projects/{username}/env-vars.
 */
class ApplyEnvVars implements ShouldQueue
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
     * @param  list<string>  $replaced  the keys the run file was generated with, so the ones
     *                                  the account no longer sets are taken back out of its `environment:`
     * @param  ?int  $revokeTokenId  the token this apply replaces, revoked once the
     *                               application is up on the new one
     */
    public function __construct(
        public string $username,
        public array $replaced = [],
        ?int $userId = null,
        public ?int $revokeTokenId = null,
    ) {
        $this->userId = $userId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->runTask(function (): void {
            $user = $this->project();
            if ($user === null) {
                throw new TaskCancelledException;
            }
            $this->throwIfCancelled();

            $this->apply($user);
        });
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task();
        if ($task !== null && ! $task->isTerminal()) {
            $this->markFailed($e ?? new RuntimeException('Apply env vars job failed'));
        }

        (new ApiAccess(app(System::class)))->markPendingFailure($this->username);
    }

    /**
     * By row id once the job carries one: a project deleted and created again
     * under the same name is not the one this was queued for.
     */
    private function project(): ?User
    {
        return $this->userId === null
            ? User::findByUsername($this->username)
            : User::query()->whereKey($this->userId)->first();
    }

    private function apply(User $user): void
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof Dind) {
            throw new RuntimeException("Project '{$this->username}' is not a container project.");
        }

        $lock = $this->lockDeploy($user);

        try {
            $this->restartWithEnvironment($runtime, $user);
        } finally {
            $lock->release();
        }
    }

    private function restartWithEnvironment(Dind $runtime, User $user): void
    {
        $api = new ApiAccess($runtime->system());
        $runtime->applyProjectEnvVars();
        $runtime->composeWriter()->mergeAccountEnv($user->getEnvVars(), $this->replaced);
        $runtime->composeWriter()->mergeApiAccessEnv($api->envFor($user));

        $result = $runtime->projectAction('up');
        if (($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException(self::composeError($result));
        }

        $api->confirmRevocation($this->username, $this->revokeTokenId);
    }

    /**
     * The account lock a deploy holds. A deploy that took it since this was
     * queued fails this task rather than starting a second `compose up`.
     */
    private function lockDeploy(User $user): DeployLock
    {
        try {
            return DeployLock::acquireFor($user->username, DeployLock::HOLDER_ENV_VARS);
        } catch (DeployAlreadyRunningException $e) {
            $this->recordProblem($e->getMessage());

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function recordProblem(string $message): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        $problem = ProblemException::one('env_vars', 'deploy_already_running', $message);
        $task->details = array_merge($task->details ?? [], ['problems' => $problem->problems]);
        $task->save();
    }

    /**
     * @param  array{stdout?: string, stderr?: string, exit_code?: int}  $result
     */
    private static function composeError(array $result): string
    {
        $message = trim((string) ($result['stderr'] ?? ''));

        return $message === ''
            ? 'The application could not be started with the new environment variables.'
            : $message;
    }
}
