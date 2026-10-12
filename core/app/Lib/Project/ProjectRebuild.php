<?php

namespace App\Lib\Project;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Jobs\RebuildProject;
use App\Jobs\RestoreBackup;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\LogStorage;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Lib\Domains\PublicUrl;
use App\Lib\Git\GitActions;
use App\Models\Task;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\AppHealth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Deploying a project that already exists: a rebuild from ~/project (after
 * importing an archive when one is given), an archive deployed into it, or a
 * git pull, branch change or revert of its Deploy-managed checkout and the
 * rebuild after it.
 *
 * One body for both ways of running it -- the RebuildProject job behind the
 * 202, and the NDJSON stream a client can still ask for -- so the two cannot
 * answer differently.
 */
class ProjectRebuild
{
    public const REBUILD = 'rebuild';

    public const DEPLOY_ARCHIVE = 'deploy_archive';

    public const ATTACH_SOURCE = 'attach_source';

    public const GIT_PULL = 'git_pull';

    public const CHANGE_BRANCH = 'change_branch';

    public const REVERT = 'revert';

    /** Actions that change the checkout first, with their input: `path` and `strategy`, `branch` or `ref`. */
    public const GIT_ACTIONS = [self::GIT_PULL, self::CHANGE_BRANCH, self::REVERT];

    /**
     * Queue the run and return its task.
     *
     * @param array<string, string> $git the git action's input, also shown on the task
     */
    public function queue(User $user, string $action, ?string $zipPath, ?DeployPlan $plan, ?string $recipe, array $git = []): Task
    {
        $task = Task::start(
            jobType: RebuildProject::class,
            queue: 'default',
            username: $user->username,
            details: array_filter([
                'username' => $user->username,
                'domain' => $user->domain,
                'action' => $action,
                'zip_path' => $zipPath,
                ...$git,
            ], static fn (mixed $v): bool => $v !== null),
        );
        RebuildProject::dispatch($user->username, $action, $zipPath, $plan?->toArray(), $recipe, $git !== [] ? $git : null, (int) $user->getKey())->attachTask($task);

        return $task;
    }

    /**
     * The deploy or env var apply of this project that is queued or running:
     * any work that takes the account's deploy lock.
     */
    public static function pendingTask(string $username): ?Task
    {
        return Task::query()
            ->where('username', $username)
            ->whereIn('job_type', Task::DEPLOY_LOCK_JOB_TYPES)
            ->whereNotIn('status', Task::TERMINAL_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A backup or restore of this project that is queued or running. It stops
     * the stack and swaps `~/project` under the deploy lock.
     */
    public static function pendingBackupTask(string $username): ?Task
    {
        return Task::query()
            ->where('username', $username)
            ->whereIn('job_type', [CreateBackup::class, RestoreBackup::class])
            ->whereNotIn('status', Task::TERMINAL_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Refuse while a deploy of the project is queued or running, otherwise run
     * $start -- which queues the deploy or opens its log -- before another
     * request can look. Two calls racing each other cannot both get past the
     * check. The worker still takes the deploy lock, so one started some other
     * way in between (the CLI, a push) fails the queued task instead of running
     * beside it. Without the queue lock it refuses rather than go on unlocked,
     * which could queue a second deploy beside a racing one.
     *
     * @template T
     * @param callable(): T $start
     * @return T
     * @throws DeployBusyException
     */
    public function whileIdle(User $user, callable $start): mixed
    {
        $handle = $this->lockQueue(new DeployLogPaths($user->username));
        if ($handle === null) {
            throw new ServiceUnavailableHttpException(null, 'Could not lock the project to start its deploy; try again.');
        }
        try {
            $this->assertIdle($user);

            return $start();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The 409 whileIdle() answers, without its lock: for a check that must
     * not run beside a deploy, made before whileIdle() checks again. A pending
     * backup or restore counts too: a deploy queued beside it would fail inside its window.
     *
     * @throws DeployBusyException
     */
    public function assertIdle(User $user): void
    {
        $backup = self::pendingBackupTask($user->username);
        if ($backup !== null) {
            throw ProjectBusyException::taskRunning($user->username, $backup);
        }

        $task = self::pendingTask($user->username);
        if ($task !== null || DeployLogger::isLockedFor($user->username)) {
            throw new DeployBusyException($user->username, $task);
        }
    }

    /**
     * The project's queue lock, held; null when it could not be taken.
     *
     * @return resource|null
     */
    protected function lockQueue(DeployLogPaths $paths)
    {
        LogStorage::ensureDirectory($paths->directory());
        $handle = @fopen($paths->queueLock(), 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * The archive a run will import, checked before it is queued: one that is
     * missing, outside the home or not a .zip/.tar.gz is a 422 now rather
     * than a failed task later. What it holds is checked when it is imported.
     *
     * @throws ValidationException
     */
    /** An archive deploy replaces ~/project, which only a container project has. */
    public static function refuseArchiveOnClassicProject(User $user): void
    {
        if ($user->getTemplate() !== 'dind') {
            throw ValidationException::withMessages([
                'zip_path' => 'Archive deploy is only supported for dind users.',
            ]);
        }
    }

    /**
     * An archive cannot replace a project that deploys from git: the deploy would fail for want of
     * .git only after the archive replaced ~/project, and a deploy-managed repository cannot be
     * disconnected, so this says what can be done instead, before anything is touched.
     */
    public static function refuseArchiveOnGitProject(User $user): void
    {
        if (!$user->hasGitProject()) {
            return;
        }

        throw ProblemException::one(
            'zip_path',
            'archive_on_git_project',
            "Project '{$user->username}' deploys from its git repository, so an archive cannot replace it. "
            . 'Push the change to the repository and rebuild, or deploy the archive into a project created without a repository.',
            ['git_repo' => GitUrl::sanitize((string) $user->getGitRepo())]
        );
    }

    public function assertArchive(User $user, string $zipPath): void
    {
        try {
            UploadedArchive::inHome($zipPath, rtrim($this->homeOf($user), '/'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
        }
    }

    /** The home the archive import resolves zip_path against. */
    protected function homeOf(User $user): string
    {
        return $this->project($user)->homeDirPath();
    }

    protected function project(User $user): ProjectAggregate
    {
        return $user->project();
    }

    /**
     * The deploy log this run writes; null for a rebuild of a project on a
     * plain template, which has none. Opened before the run so a failure knows
     * its stage, and so a stream can send its first frame before the work.
     * A git action always starts a new deploy, as a push does: a `running` log
     * then is a dead process's, never a create waiting for files (that has no git).
     *
     * @throws DeployAlreadyRunningException
     */
    public function openLog(User $user, string $action): ?DeployLogger
    {
        if ($action === self::REBUILD && $user->getTemplate() !== 'dind') {
            return null;
        }
        if (in_array($action, self::GIT_ACTIONS, true)) {
            return DeployLogger::startSafely($user->username);
        }

        $logger = DeployLogger::resumeRunningOrStartSafely($user->username);
        if ($action === self::DEPLOY_ARCHIVE) {
            $logger?->info('Deploy started (source: archive)');
        }

        return $logger;
    }

    /**
     * Run it to the end. The deploy log is finished either way; a failure is
     * thrown in the shape the API answers it in -- a ProblemException with a
     * code and the stage, or a refused archive as a 422 on zip_path.
     *
     * @param array<string, string> $git a git action's input
     * @return array<string, mixed> what to add to the task's details
     * @throws ValidationException
     */
    public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath, array $git = []): array
    {
        if ($action === self::DEPLOY_ARCHIVE) {
            $this->deployArchive($user, $logger, (string) $zipPath);

            return [];
        }
        if (in_array($action, self::GIT_ACTIONS, true)) {
            return $this->changeCheckout($user, $action, $logger, $git);
        }

        if ($action === self::ATTACH_SOURCE) {
            $this->rebuild($user, $logger, null);

            return [];
        }

        $this->rebuild($user, $logger, $zipPath);

        return [];
    }

    /**
     * The git action and the rebuild after it, in this process: the tree the
     * app was deployed from is kept aside only for the process that changes
     * the checkout, and is put back if the new version fails.
     *
     * @param array<string, string> $git
     * @return array{commit: ?string, checkout: array<string, mixed>}
     */
    private function changeCheckout(User $user, string $action, ?DeployLogger $logger, array $git): array
    {
        try {
            return $this->gitActions()->redeploy($user, $action, $git, $logger);
        } catch (\Exception $e) {
            if (self::isUnansweredCancel($e, $logger)) {
                $message = $e instanceof DeployCancelledException ? $e->getMessage() : 'Deployment cancelled by user';
                throw self::cancellation($logger, $message);
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            throw self::rebuildFailure($e, $logger);
        }
    }

    /**
     * A cancel not yet answered as `deploy_cancelled`. A cancel during the git step reaches
     * here as git's own failure, so the log's cancelled status counts too.
     */
    private static function isUnansweredCancel(\Exception $e, ?DeployLogger $logger): bool
    {
        $answered = $e instanceof ProblemException && ($e->problems[0]['code'] ?? null) === 'deploy_cancelled';

        return !$answered && ($e instanceof DeployCancelledException || $logger?->isCancelled() === true);
    }

    /** Finishes the log as cancelled and answers it, naming the stage it was cancelled in. */
    private static function cancellation(?DeployLogger $logger, string $message): ProblemException
    {
        $stage = $logger?->currentStage();
        self::finishRebuildLog($logger, DeployLogger::STATUS_CANCELLED, $message);

        return ProblemException::deploy('deploy_cancelled', $message, $stage);
    }

    protected function gitActions(): GitActions
    {
        return app(GitActions::class);
    }

    /**
     * A DinD rebuild wipes and rebuilds through DeploymentWorkflow; PhpHosting only recreates
     * the outer hosting. A failure answers with a code and stage like every other deploy endpoint;
     * a ValidationException (ProblemException is one) is already in that shape and passes through,
     * and so does the lock conflict a template rebuild takes in here rather than in openLog().
     */
    private function rebuild(User $user, ?DeployLogger $logger, ?string $zipPath): void
    {
        try {
            $this->project($user)->rebuildFromSource($logger, $zipPath);
            $this->project($user)->system()->webserver()->rebuildDomains();
            self::recordSucceeded($user);
        } catch (DeployCancelledException $e) {
            throw self::cancellation($logger, $e->getMessage());
        } catch (ValidationException|DeployAlreadyRunningException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw self::rebuildFailure($e, $logger);
        }
    }

    /**
     * The vhosts were rendered at account creation against the welcome app's port, and detection
     * has just re-pointed app_port at what the archive serves on, so the proxy is re-rendered as a
     * rebuild does it, or every non-8080 app answers 502 behind a green deploy. A ProblemException
     * from the start already carries its rule; finish() is a no-op once the log is finished.
     */
    private function deployArchive(User $user, ?DeployLogger $logger, string $zipPath): void
    {
        try {
            $this->project($user)->deployment()->deployFromArchive($logger, $zipPath);
            $this->project($user)->system()->webserver()->rebuildDomains();
            self::recordSucceeded($user);
        } catch (DeployCancelledException $e) {
            $stage = $logger?->currentStage();
            $logger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (\InvalidArgumentException $e) {
            $logger?->finish(DeployLogger::STATUS_FAILED, $e->getMessage());
            throw ValidationException::withMessages([
                'zip_path' => $e->getMessage(),
            ]);
        } catch (ProblemException $e) {
            $logger?->finish(DeployLogger::STATUS_FAILED, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            throw self::archiveFailure($e, $logger);
        }
    }

    private static function archiveFailure(\Exception $e, ?DeployLogger $logger): ProblemException
    {
        $logger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
        $stage = $logger?->currentStage();
        $logger?->finish(DeployLogger::STATUS_FAILED, $message);

        return ProblemException::deploy($match['rule'] ?? 'deploy_failed', $message, $stage);
    }

    /**
     * A failed rebuild, in the shape every other deploy endpoint answers in, named
     * with the same slug deploy telemetry reports so a client and a dashboard agree.
     */
    private static function rebuildFailure(\Exception $e, ?DeployLogger $logger): ProblemException
    {
        $logger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
        $stage = $logger?->currentStage();
        self::finishRebuildLog($logger, DeployLogger::STATUS_FAILED, $message);

        return ProblemException::deploy($match['rule'] ?? 'rebuild_failed', $message, $stage);
    }

    /**
     * The workflow finishes the log itself when a rebuild fails or is cancelled, and a second
     * finish would log and report the same failure twice. A cancel request alone sets the
     * status without finishing, so `finished_at` is what says it was done.
     */
    private static function finishRebuildLog(?DeployLogger $logger, string $status, string $error): void
    {
        if ($logger === null) {
            return;
        }
        $latest = $logger->readLatest() ?? [];
        $settled = in_array($latest['status'] ?? null, [DeployLogger::STATUS_FAILED, DeployLogger::STATUS_CANCELLED], true);
        if ($settled && ($latest['finished_at'] ?? null) !== null) {
            return;
        }
        $logger->finish($status, $error);
    }

    /**
     * A rebuild that worked is a deploy that worked: the deployment status decides the
     * entrypoint's install/upgrade phase ({@see PlatformStage::phaseFor()}), so an account
     * that was only ever rebuilt must not keep running its first install.
     */
    public static function recordSucceeded(User $user): void
    {
        $warnings = self::servingWarnings($user);
        if ($warnings !== []) {
            $user->setDetails([
                'deployment_status' => 'partial',
                'deployment_warnings' => $warnings,
                'error' => null,
                'app_stopped_by_request' => null,
            ]);
            $user->save();

            return;
        }

        if (self::alreadyCleanSuccess($user)) {
            return;
        }
        $user->markDeploySucceeded();
        $user->save();
    }

    /**
     * The health and public-URL verdicts the pipeline finishes its log with, applied to the
     * account record only: a rebuild does not run the pipeline, and the log is finished by
     * {@see \App\System\Project\Deployment\DeploymentWorkflow::rebuildFromSource()}.
     *
     * @return list<string>
     */
    private static function servingWarnings(User $user): array
    {
        return array_merge(
            AppHealth::servingWarnings($user->getDetails()),
            PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        );
    }

    /** A clean rebuild still clears a previous run's warnings, which would report a fault since fixed. */
    private static function alreadyCleanSuccess(User $user): bool
    {
        return $user->getDeploymentStatus() === 'success' && ($user->getDetails()['deployment_warnings'] ?? []) === []
            && !$user->isAppStoppedByRequest();
    }
}
