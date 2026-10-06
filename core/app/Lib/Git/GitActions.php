<?php

namespace App\Lib\Git;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\DeployHook\DeployHooks;
use App\Jobs\DeployProject;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Validation\ValidationException;

/**
 * The git porcelain on a project's checkout, shared by the /git endpoints and
 * the git:* commands. Each action takes its endpoint's validated input and
 * returns the checkout's data; a git refusal is thrown. The endpoints queue a
 * pull, branch change or revert of a Deploy-managed checkout instead
 * ({@see queueRedeploy()}); the commands run it here.
 */
class GitActions
{
    public function __construct(private readonly DeployHooks $hooks)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function status(User $user, array $params, bool $fetch): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->status($fetch));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function branches(User $user, array $params): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->branches());
    }

    /**
     * @param array<string, mixed> $params
     */
    public function commits(User $user, array $params): array
    {
        $limit = isset($params['limit']) ? (int) $params['limit'] : null;
        $branch = isset($params['branch']) && is_string($params['branch']) && $params['branch'] !== ''
            ? $params['branch']
            : null;

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->commits($limit, $branch));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connectRemote(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        $this->assertSshRemoteUsable($user, (string) ($params['repo_url'] ?? ''));

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->connect(
            $params['repo_url'] ?? '',
            $params['branch'] ?? '',
            $params['token'] ?? null,
            (bool) ($params['repair'] ?? false),
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function disconnect(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $this->hooks->disconnectAndForget($user, $git));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function changeBranch(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->changeBranch($params['branch']), true);
    }

    /**
     * Omitting `token` leaves the stored one alone; sending it, even empty, replaces it.
     *
     * @param array<string, mixed> $params
     */
    public function updateCredentials(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        $provided = array_key_exists('token', $params);

        return $this->run(
            $user,
            $params['path'] ?? null,
            fn (Git $git) => $git->updateCredentials($provided ? ($params['token'] ?? null) : null, $provided),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function pull(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->pull($params['strategy'] ?? null), true);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function push(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->push());
    }

    /**
     * @param array<string, mixed> $params
     */
    public function revert(User $user, array $params): array
    {
        $this->refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->revert($params['ref'] ?? null), true);
    }

    /**
     * Create the project's SSH deploy key, or return the one it has, pinning
     * `host` when given.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function deployKey(User $user, array $params): array
    {
        return app(DeployKey::class)->ensure($user, isset($params['host']) ? (string) $params['host'] : null);
    }

    /**
     * An SSH remote clones only with the project's deploy key, and only from a
     * host whose key is pinned. Refused here, before anything is connected.
     */
    private function assertSshRemoteUsable(User $user, string $repoUrl): void
    {
        if (!GitRepoInput::isSsh($repoUrl)) {
            return;
        }

        if (DeployKey::stored($user) === null) {
            $https = GitRepoInput::httpsEquivalent($repoUrl);
            throw ProblemException::one('repo_url', 'repo_url_ssh_needs_deploy_key',
                'This project has no deploy key, so an SSH remote cannot authenticate. Create one with '
                . 'POST /projects/{name}/git/deploy-key, add its public key to the repository as a deploy key, '
                . 'and connect again' . ($https !== null ? ", or connect {$https} with a token." : '.'),
                array_filter(['suggestion' => $https]));
        }

        $problem = GitRepoInput::sshProblemWithKey('repo_url', $repoUrl, fn (string $host): bool => DeployKey::pins($user, $host));
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /**
     * Run `$work` on the checkout `$path` names. A git refusal the layer marks
     * 422 is a validation error under `git`; any other is rethrown without
     * credentials in its message, still carrying its status for the endpoints.
     *
     * @template T
     * @param callable(Git, ProjectAggregate): T $work
     * @return T
     * @throws GitException
     * @throws ValidationException
     */
    public function onCheckout(User $user, ?string $path, callable $work): mixed
    {
        try {
            $project = $user->project();

            return $work($project->git($path), $project);
        } catch (GitException $e) {
            if ($e->httpStatus === 422) {
                throw $e->problemCode !== null
                    ? ProblemException::one('git', $e->problemCode, $e->getMessage())
                    : ValidationException::withMessages(['git' => $e->getMessage()]);
            }

            throw new GitException(GitUrl::sanitize($e->getMessage()), $e->httpStatus);
        }
    }

    /**
     * A change to the checkout while the project's create is still running
     * would reach an account that may not exist yet (`sudo: unknown user`);
     * one while a queued rebuild or archive deploy runs would change the files
     * it is building from.
     *
     * @throws GitException
     */
    private function refuseWhileCreating(User $user): void
    {
        $task = ProjectRebuild::pendingTask($user->username);
        if ($task === null) {
            return;
        }

        throw new GitException(
            $task->job_type === DeployProject::class
                ? "Project '{$user->username}' is still being created; try again when its deploy has finished."
                : "A deploy of project '{$user->username}' is queued or running (task {$task->id}); try again when it has finished.",
            409,
            taskId: $task->id,
        );
    }

    /**
     * On a Deploy-managed DinD checkout, check a pull, branch change or revert
     * here and queue it with the rebuild after it; the task, or null when the
     * checkout has nothing to rebuild and the caller runs the action itself.
     * The checkout is changed by the job, not here: the tree the app was
     * deployed from is kept aside, and put back after a failed rebuild, only
     * by the process that changes it.
     *
     * @param array<string, mixed> $params the endpoint's validated input
     * @throws DeployBusyException while a deploy of the project is queued or running
     */
    public function queueRedeploy(User $user, string $action, array $params): ?Task
    {
        return $this->onCheckout($user, $params['path'] ?? null, function (Git $git, ProjectAggregate $project) use ($user, $action, $params): ?Task {
            if (!$git->isDeployManaged() || !$project->runtime() instanceof Dind) {
                return null;
            }
            $rebuild = app(ProjectRebuild::class);
            // Before the checks below, which must not read a checkout a deploy is moving aside.
            $rebuild->assertIdle($user);
            match ($action) {
                ProjectRebuild::GIT_PULL => $git->assertPullable($params['strategy'] ?? null),
                ProjectRebuild::CHANGE_BRANCH => $git->assertBranchChangeable((string) $params['branch']),
                ProjectRebuild::REVERT => $git->assertRevertable($params['ref'] ?? null),
            };
            $input = array_filter([
                'path' => $git->pathKey(),
                'strategy' => $action === ProjectRebuild::GIT_PULL ? ($params['strategy'] ?? Git::STRATEGY_FF) : null,
                'branch' => $action === ProjectRebuild::CHANGE_BRANCH ? (string) $params['branch'] : null,
                'ref' => $action === ProjectRebuild::REVERT ? ($params['ref'] ?? 'HEAD') : null,
            ], static fn (?string $v): bool => $v !== null);

            return $rebuild->whileIdle($user, fn (): Task => $rebuild->queue($user, $action, null, null, null, $input));
        });
    }

    /**
     * What a queued pull, branch change or revert runs: the change, then the
     * rebuild, under the deploy `$logger` the job opened -- and whose lock it
     * holds -- before the checkout is touched.
     *
     * @param array<string, string> $input what queueRedeploy() recorded
     * @return array{commit: ?string, checkout: array<string, mixed>}
     */
    public function redeploy(User $user, string $action, array $input, ?DeployLogger $logger): array
    {
        $logger?->info(match ($action) {
            ProjectRebuild::GIT_PULL => 'Pulling the connected branch (' . ($input['strategy'] ?? Git::STRATEGY_FF) . ') before the rebuild',
            ProjectRebuild::CHANGE_BRANCH => "Changing the branch to {$input['branch']} before the rebuild",
            ProjectRebuild::REVERT => 'Reverting the checkout to ' . ($input['ref'] ?? 'HEAD') . ' before the rebuild',
        });
        $change = match ($action) {
            ProjectRebuild::GIT_PULL => fn (Git $git): array => $git->pull($input['strategy'] ?? null),
            ProjectRebuild::CHANGE_BRANCH => fn (Git $git): array => $git->changeBranch($input['branch']),
            ProjectRebuild::REVERT => fn (Git $git): array => $git->revert($input['ref'] ?? null),
        };

        return $this->onCheckout(
            $user,
            $input['path'] ?? null,
            fn (Git $git, ProjectAggregate $project): array => $this->changeAndRebuild($git, $project, $change, $logger),
        );
    }

    /**
     * @param callable(Git): array<mixed> $action
     * @return array<mixed>
     */
    private function run(User $user, ?string $path, callable $action, bool $redeployIfManaged = false): array
    {
        return $this->onCheckout($user, $path, function (Git $git, ProjectAggregate $project) use ($action, $redeployIfManaged): array {
            if (!$redeployIfManaged) {
                return $action($git);
            }

            return $this->changeAndRebuild($git, $project, $action, null)['checkout'];
        });
    }

    /**
     * The change, and the rebuild after it, with the served tree kept aside.
     * Given a `$logger`, a change git refuses, or one cancelled while git
     * runs, closes that deploy before the tree is settled; the rebuild
     * finishes it otherwise. A tree put back brings its branch records back
     * ({@see \App\System\Project\Dind\Generation\CheckoutAside}).
     *
     * @param callable(Git): array<mixed> $action
     * @return array{commit: ?string, checkout: array<mixed>}
     */
    private function changeAndRebuild(Git $git, ProjectAggregate $project, callable $action, ?DeployLogger $logger): array
    {
        $redeploy = app(CheckoutRedeploy::class);

        return $redeploy->keepingTheServedTree($git, $project, function () use ($action, $git, $project, $redeploy, $logger): array {
            try {
                $data = $action($git);
            } catch (\Throwable $e) {
                // Unfinished, not only running: a cancel request sets `cancelled` without
                // finishing, and the lock it keeps would stop the served tree coming back.
                if ($logger !== null && !$logger->isFinished()) {
                    $logger->finish(DeployLogger::STATUS_FAILED, 'Could not change the checkout: ' . GitUrl::sanitize($e->getMessage()));
                }
                throw $e;
            }
            $commit = $git->readHeadCommit();
            $redeploy->afterMutation($git, $project, 'git', $commit, $logger);
            if ($logger?->isRunning()) {
                // Nothing to rebuild: the runtime returned without finishing it.
                $logger->finish(DeployLogger::STATUS_SUCCESS);
            }

            return ['commit' => $commit, 'checkout' => $data];
        });
    }
}
