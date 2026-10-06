<?php

namespace App\Lib\Git;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\DeployHook\DeployHooks;
use App\Jobs\DeployProject;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Git;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Validation\ValidationException;

/**
 * The git porcelain on a project's checkout, shared by the /git endpoints and
 * the git:* commands. Each action takes its endpoint's validated input and
 * returns the checkout's data; a git refusal is thrown.
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
            $redeploy = app(CheckoutRedeploy::class);

            return $redeploy->keepingTheServedTree($git, $project, function () use ($action, $git, $project, $redeploy): array {
                $data = $action($git);
                $redeploy->afterMutation($git, $project);

                return $data;
            });
        });
    }
}
