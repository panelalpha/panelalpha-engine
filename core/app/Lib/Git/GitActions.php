<?php

namespace App\Lib\Git;

use App\Lib\Deploy\Source\GitUrl;
use App\Lib\DeployHook\DeployHooks;
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
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $this->hooks->disconnectAndForget($user, $git));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function changeBranch(User $user, array $params): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->changeBranch($params['branch']), true);
    }

    /**
     * Omitting `token` leaves the stored one alone; sending it, even empty, replaces it.
     *
     * @param array<string, mixed> $params
     */
    public function updateCredentials(User $user, array $params): array
    {
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
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->pull($params['strategy'] ?? null), true);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function push(User $user, array $params): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->push());
    }

    /**
     * @param array<string, mixed> $params
     */
    public function revert(User $user, array $params): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->revert($params['ref'] ?? null), true);
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
                throw ValidationException::withMessages(['git' => $e->getMessage()]);
            }

            throw new GitException(GitUrl::sanitize($e->getMessage()), $e->httpStatus);
        }
    }

    /**
     * @param callable(Git): array<mixed> $action
     * @return array<mixed>
     */
    private function run(User $user, ?string $path, callable $action, bool $redeployIfManaged = false): array
    {
        return $this->onCheckout($user, $path, function (Git $git, ProjectAggregate $project) use ($action, $redeployIfManaged): array {
            $data = $action($git);
            if ($redeployIfManaged) {
                app(CheckoutRedeploy::class)->afterMutation($git, $project);
            }

            return $data;
        });
    }
}
