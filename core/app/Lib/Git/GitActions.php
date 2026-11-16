<?php

namespace App\Lib\Git;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\DeployHook\DeployHooks;
use App\Lib\Net\EgressGuard;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Git;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Git\CouldNotRun;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Validation\ValidationException;

/**
 * The git porcelain on a project's checkout for the /git endpoints and the git:* commands; a git refusal is thrown.
 * The endpoints queue a pull, branch change or revert ({@see queueRedeploy()}); the commands run it ({@see changeHere()}).
 */
class GitActions
{
    /**
     * Most commits one `git_commits` / GET /git/commits may ask for: the output
     * is read whole into memory, and core runs with `memory_limit = -1`.
     */
    public const MAX_COMMITS = 1000;

    public function __construct(private readonly DeployHooks $hooks) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function status(User $user, array $params, bool $fetch): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->status($fetch));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function branches(User $user, array $params): array
    {
        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->branches());
    }

    /**
     * @param  array<string, mixed>  $params
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
     * @param  array<string, mixed>  $params
     */
    public function connectRemote(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        $this->assertSshRemoteUsable($user, (string) ($params['repo_url'] ?? ''));
        $this->assertRemoteAllowed((string) ($params['repo_url'] ?? ''));

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->connect(
            $params['repo_url'] ?? '',
            $params['branch'] ?? '',
            $params['token'] ?? null,
            (bool) ($params['repair'] ?? false),
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function disconnect(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $this->hooks->disconnectAndForget($user, $git));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function changeBranch(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        return $this->changeHere($user, ProjectRebuild::CHANGE_BRANCH, $params);
    }

    /**
     * Omitting `token` leaves the stored one alone; sending it, even empty, replaces it.
     *
     * @param  array<string, mixed>  $params
     */
    public function updateCredentials(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        $provided = array_key_exists('token', $params);

        return $this->run(
            $user,
            $params['path'] ?? null,
            fn (Git $git) => $git->updateCredentials($provided ? ($params['token'] ?? null) : null, $provided),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function pull(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        return $this->changeHere($user, ProjectRebuild::GIT_PULL, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function push(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        return $this->run($user, $params['path'] ?? null, fn (Git $git) => $git->push());
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function revert(User $user, array $params): array
    {
        CheckoutChangeGuard::refuseWhileCreating($user);

        return $this->changeHere($user, ProjectRebuild::REVERT, $params);
    }

    /**
     * Create the project's SSH deploy key, or return the one it has, pinning
     * `host` when given.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function deployKey(User $user, array $params): array
    {
        return app(DeployKey::class)->ensure($user, isset($params['host']) ? (string) $params['host'] : null);
    }

    /**
     * The connect runs git on the host, whose network reaches the metadata and internal services: a host the
     * egress guard refuses, or cannot resolve to check, is a 422 before anything is connected.
     *
     * @throws ProblemException
     */
    private function assertRemoteAllowed(string $repoUrl): void
    {
        if ($repoUrl === '') {
            return;
        }
        if (GitRepoInput::isSsh($repoUrl)) {
            $this->assertSshRemoteAddressAllowed($repoUrl);

            return;
        }

        $problem = EgressGuard::problem('repo_url', $repoUrl);
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /**
     * A pinned host key proves who answers, not where: the SSH host is checked
     * like the deploy key's scan, refused when internal or unresolvable.
     *
     * @throws ProblemException
     */
    private function assertSshRemoteAddressAllowed(string $repoUrl): void
    {
        $problem = RepositoryAddress::problem($repoUrl, 'repo_url');
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /**
     * An SSH remote clones only with the project's deploy key, and only from a
     * host whose key is pinned. Refused here, before anything is connected.
     */
    private function assertSshRemoteUsable(User $user, string $repoUrl): void
    {
        if (! GitRepoInput::isSsh($repoUrl)) {
            return;
        }

        if (DeployKey::stored($user) === null) {
            $https = GitRepoInput::httpsEquivalent($repoUrl);
            throw ProblemException::one('repo_url', 'repo_url_ssh_needs_deploy_key',
                'This project has no deploy key, so an SSH remote cannot authenticate. Create one, add its public '
                .'key to the repository as a deploy key, and connect again'.($https !== null ? ", or connect {$https} with a token." : '.'),
                array_filter(['suggestion' => $https]));
        }

        $problem = GitRepoInput::sshProblemWithKey('repo_url', $repoUrl, fn (string $host): bool => DeployKey::pins($user, $host));
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /**
     * Run `$work` on the checkout `$path` names. A git refusal marked 422 is a validation error under `git`;
     * any other is rethrown without credentials in its message, still carrying its status.
     *
     * @template T
     *
     * @param  callable(Git, ProjectAggregate): T  $work
     * @return T
     *
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

            throw new GitException(GitUrl::sanitize($e->getMessage()), $e->httpStatus, $e->problemCode, $e->taskId);
        }
    }

    /**
     * Check a pull, branch change or revert of a Deploy-managed DinD checkout and queue it with the rebuild; null
     * when there is nothing to rebuild. The job changes it, as only that process may put the served tree back.
     *
     * @param  array<string, mixed>  $params  the endpoint's validated input
     *
     * @throws DeployBusyException while a deploy of the project is queued or running
     */
    public function queueRedeploy(User $user, string $action, array $params): ?Task
    {
        return $this->onCheckout(
            $user,
            $params['path'] ?? null,
            fn (Git $git, ProjectAggregate $project): ?Task => $this->queueIfRebuildable($user, $git, $project, $action, $params),
        );
    }

    /**
     * The project is checked idle before the change is, since those checks must not read a
     * checkout a deploy is moving aside.
     *
     * @param  array<string, mixed>  $params
     */
    private function queueIfRebuildable(User $user, Git $git, ProjectAggregate $project, string $action, array $params): ?Task
    {
        if (! self::rebuildsInTheAccount($git, $project)) {
            return null;
        }
        $rebuild = app(ProjectRebuild::class);
        $rebuild->assertIdle($user);
        $this->assertChangeAllowedWhereGitRuns($git, $action, $params);
        $input = $this->queuedInput($git, $action, $params);

        return $rebuild->whileIdle($user, fn (): Task => $rebuild->queue($user, $action, null, null, null, $input));
    }

    /**
     * A container git could not run in is started, or repaired, before the change, and the change refuses there what
     * these checks would refuse here.
     *
     * @param  array<string, mixed>  $params
     */
    private function assertChangeAllowedWhereGitRuns(Git $git, string $action, array $params): void
    {
        try {
            $this->assertChangeAllowed($git, $action, $params);
        } catch (CouldNotRun) {
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertChangeAllowed(Git $git, string $action, array $params): void
    {
        match ($action) {
            ProjectRebuild::GIT_PULL => $git->assertPullable($params['strategy'] ?? null),
            ProjectRebuild::CHANGE_BRANCH => $git->assertBranchChangeable((string) $params['branch']),
            ProjectRebuild::REVERT => $git->assertRevertable($params['ref'] ?? null),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string> what the queued job hands back to redeploy()
     */
    private function queuedInput(Git $git, string $action, array $params): array
    {
        return array_filter([
            'path' => $git->pathKey(),
            'strategy' => $action === ProjectRebuild::GIT_PULL ? ($params['strategy'] ?? Git::STRATEGY_FF) : null,
            'branch' => $action === ProjectRebuild::CHANGE_BRANCH ? (string) $params['branch'] : null,
            'ref' => $action === ProjectRebuild::REVERT ? ($params['ref'] ?? 'HEAD') : null,
        ], static fn (?string $v): bool => $v !== null);
    }

    /**
     * What a queued pull, branch change or revert runs: the change, then the rebuild, under the deploy
     * `$logger` the job opened, and whose lock it holds, before the checkout is touched.
     *
     * @param  array<string, string>  $input  what queueRedeploy() recorded
     * @return array{commit: ?string, checkout: array<string, mixed>}
     */
    public function redeploy(User $user, string $action, array $input, ?DeployLogger $logger): array
    {
        $logger?->info($this->redeployAnnouncement($action, $input));
        $change = $this->checkoutChange($action, $input);

        return $this->onCheckout(
            $user,
            $input['path'] ?? null,
            fn (Git $git, ProjectAggregate $project): array => $this->changeAndRebuild($git, $project, $change, $logger),
        );
    }

    /**
     * @param  array<string, string>  $input
     */
    private function redeployAnnouncement(string $action, array $input): string
    {
        return match ($action) {
            ProjectRebuild::GIT_PULL => 'Pulling the connected branch ('.($input['strategy'] ?? Git::STRATEGY_FF).') before the rebuild',
            ProjectRebuild::CHANGE_BRANCH => "Changing the branch to {$input['branch']} before the rebuild",
            ProjectRebuild::REVERT => 'Reverting the checkout to '.($input['ref'] ?? 'HEAD').' before the rebuild',
        };
    }

    /**
     * @param  array<string, string>  $input
     * @return callable(Git): array<mixed>
     */
    private function checkoutChange(string $action, array $input): callable
    {
        return match ($action) {
            ProjectRebuild::GIT_PULL => fn (Git $git): array => $git->pull($input['strategy'] ?? null),
            ProjectRebuild::CHANGE_BRANCH => fn (Git $git): array => $git->changeBranch($input['branch']),
            ProjectRebuild::REVERT => fn (Git $git): array => $git->revert($input['ref'] ?? null),
        };
    }

    /**
     * @param  callable(Git): array<mixed>  $action
     * @return array<mixed>
     */
    private function run(User $user, ?string $path, callable $action): array
    {
        return $this->onCheckout($user, $path, fn (Git $git): array => $action($git));
    }

    /**
     * A pull, branch change or revert run in this process. A Deploy-managed DinD checkout changes as the queued job
     * changes it, under a deploy of its own ({@see changeUnderOwnDeploy()}); any other has nothing to rebuild.
     *
     * @param  array<string, mixed>  $params  the endpoint's validated input
     * @return array<mixed> the checkout after the change
     */
    private function changeHere(User $user, string $action, array $params): array
    {
        return $this->onCheckout($user, $params['path'] ?? null, function (Git $git, ProjectAggregate $project) use ($user, $action, $params): array {
            if (! self::rebuildsInTheAccount($git, $project)) {
                return $this->changeAndRebuild($git, $project, $this->checkoutChange($action, $this->queuedInput($git, $action, $params)), null)['checkout'];
            }

            return $this->changeUnderOwnDeploy($user, $git, $project, $action, $params)['checkout'];
        });
    }

    /**
     * The change and its rebuild as the queued job runs them, under a deploy opened first so its lock holds off pushes and
     * the history records it. A lock already held, or a change the endpoint would refuse, is refused before that deploy.
     *
     * @param  array<string, mixed>  $params  the endpoint's validated input
     * @return array<string, mixed> the commit deployed and the checkout
     *
     * @throws DeployAlreadyRunningException when a deploy took the lock after that refusal
     */
    private function changeUnderOwnDeploy(User $user, Git $git, ProjectAggregate $project, string $action, array $params): array
    {
        CheckoutChangeGuard::refuseWhileTheLockIsHeld($project, null);
        $this->assertChangeAllowedWhereGitRuns($git, $action, $params);
        $rebuild = app(ProjectRebuild::class);
        $logger = $rebuild->openLogWithoutTask($user, $action);

        return $rebuild->run($user, $action, $logger, null, $this->queuedInput($git, $action, $params));
    }

    /** A checkout whose change rebuilds the app in the account's container. */
    private static function rebuildsInTheAccount(Git $git, ProjectAggregate $project): bool
    {
        return $git->isDeployManaged() && $project->runtime() instanceof Dind;
    }

    /**
     * The change and the rebuild, with the served tree kept aside; a tree put back brings its branch records back
     * ({@see CheckoutAside}). Given a `$logger`, a refused or cancelled change closes that deploy before the settle.
     *
     * @param  callable(Git): array<mixed>  $action
     * @return array{commit: ?string, checkout: array<mixed>}
     */
    private function changeAndRebuild(Git $git, ProjectAggregate $project, callable $action, ?DeployLogger $logger): array
    {
        if (self::rebuildsInTheAccount($git, $project)) {
            CheckoutChangeGuard::refuseWhileTheLockIsHeld($project, $logger);
        }

        $redeploy = app(CheckoutRedeploy::class);
        $this->startTheAccount($git, $project, $redeploy, $logger);

        return $redeploy->keepingTheServedTree(
            $git,
            $project,
            fn (): array => $this->changeThenRebuild($git, $project, $redeploy, $action, $logger),
        );
    }

    /**
     * Git runs in the account's container, so a stopped account is started first, as a push starts it, and before the
     * served tree is kept aside, which only a running app has. A start that fails closes the deploy with its own error.
     */
    private function startTheAccount(Git $git, ProjectAggregate $project, CheckoutRedeploy $redeploy, ?DeployLogger $logger): void
    {
        try {
            $redeploy->startAccountIfStopped($git, $project, $logger);
        } catch (\Throwable $e) {
            self::failUnfinished($logger, 'Could not start the account: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * A refused change closes an unfinished deploy, not only a running one: a cancelled one keeps its lock, which
     * would stop the served tree coming back. A deploy left running by a runtime with nothing to rebuild ends here.
     *
     * @param  callable(Git): array<mixed>  $action
     * @return array{commit: ?string, checkout: array<mixed>}
     */
    private function changeThenRebuild(Git $git, ProjectAggregate $project, CheckoutRedeploy $redeploy, callable $action, ?DeployLogger $logger): array
    {
        try {
            $data = $action($git);
        } catch (\Throwable $e) {
            self::failUnfinished($logger, 'Could not change the checkout: '.$e->getMessage());
            throw $e;
        }
        $commit = $git->readHeadCommit();
        $redeploy->afterMutation($git, $project, 'git', $commit, $logger);
        if ($logger?->isRunning()) {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        return ['commit' => $commit, 'checkout' => $data];
    }

    private static function failUnfinished(?DeployLogger $logger, string $error): void
    {
        if ($logger !== null && ! $logger->isFinished()) {
            $logger->finish(DeployLogger::STATUS_FAILED, GitUrl::sanitize($error));
        }
    }
}
