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
use App\Lib\Deploy\DeployLog\DeployRequest;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\DeployLog\LogStorage;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Lib\Git\GitActions;
use App\Lib\Task\TaskTrigger;
use App\Lib\Upload\ArchiveSource;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Vault\RequestVault;
use App\Models\Task;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Dind\AppHealth;
use App\System\Project\FileManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Deploying a project that exists: a rebuild from ~/project, an archive deployed into it, or a git action on its checkout
 * and the rebuild after it. One body for the RebuildProject job, the NDJSON stream and the git:* commands, so they cannot
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
     * @param  array<string, string>  $git  the git action's input, also shown on the task
     * @param  bool  $ownsArchive  the archive at $zipPath was put into the project for this run alone, which removes it once it ends
     * @param  array<string, mixed>  $details  more to show on the task, such as the source an attach deploys
     * @param  ?ArchiveSource  $archive  an archive deploy's upload or URL, put into the project by the job
     */
    public function queue(
        User $user,
        string $action,
        ?string $zipPath,
        ?DeployPlan $plan,
        ?string $recipe,
        array $git = [],
        bool $ownsArchive = false,
        array $details = [],
        ?ArchiveSource $archive = null,
    ): Task {
        $task = Task::start(
            jobType: RebuildProject::class,
            queue: 'default',
            username: $user->username,
            details: array_filter([
                'username' => $user->username,
                'domain' => $user->domain,
                'action' => $action,
                'zip_path' => $zipPath,
                'trigger' => TaskTrigger::current(),
                ...$git,
                ...$details,
            ], static fn (mixed $v): bool => $v !== null),
        );
        RebuildProject::dispatch(
            $user->username, $action, $zipPath, $plan?->toArray(), $recipe, $git !== [] ? $git : null, (int) $user->getKey(), $ownsArchive, $archive?->toArray(),
        )->attachTask($task);

        return $task;
    }

    /**
     * The env vars to store for a redeploy that sent them: vault references read, merged onto the ones the project carries.
     * Anything but an array clears them, as a null `env_vars` does.
     *
     * @return array<string, string>
     */
    public static function sentEnvVars(User $user, mixed $sent): array
    {
        $resolved = is_array($sent) ? RequestVault::resolve('env_vars', $sent, $user->username) : null;

        return EnvVarOverrides::applyIncoming($resolved, $user->getEnvVars());
    }

    /**
     * What {@see sentEnvVars()} returned, stored with the keys a vault reference set; null leaves the env vars as they are.
     *
     * @param  ?array<string, string>  $envVars
     * @param  mixed  $sent  the env_vars as sent, before their vault references were read
     */
    public function storeEnvVars(User $user, ?array $envVars, mixed $sent): void
    {
        if ($envVars === null) {
            return;
        }
        $user->setEnvVars($envVars, $sent);
        $user->save();
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
     * Refuse while a deploy is queued or running, else run $start (queue it or open its log) before a racing call can pass the
     * check; without the queue lock it refuses rather than risk a second deploy. The worker's deploy lock fails one beside a CLI or push.
     *
     * @template T
     *
     * @param  callable(): T  $start
     * @return T
     *
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
     * The 409 whileIdle() answers, without its lock, for a check that must not run beside a deploy, made before whileIdle()
     * checks again. A pending backup or restore counts too: a deploy queued beside it would fail inside its window.
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
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * The archive a run will import, checked before it is queued, so a missing or foreign archive is a 422 rather than
     * a failed task. What it holds is checked when it is imported.
     *
     * @throws ValidationException
     */
    /** An archive deploy replaces ~/project, which only a container project has. */
    public static function refuseArchiveOnClassicProject(User $user): void
    {
        if ($user->getTemplate() !== 'dind') {
            throw ValidationException::withMessages([
                'zip_path' => 'Archive deploy works only on container projects (template dind).',
            ]);
        }
    }

    /**
     * An archive cannot replace a git project: the deploy would fail for want of .git after the archive replaced ~/project,
     * and a deploy-managed repository cannot be disconnected, so this says what to do instead, before anything is touched.
     */
    public static function refuseArchiveOnGitProject(User $user): void
    {
        if (! $user->hasGitProject()) {
            return;
        }

        throw ProblemException::one(
            'zip_path',
            'archive_on_git_project',
            "Project '{$user->username}' deploys from its git repository, so an archive cannot replace it. "
            .'Push the change to the repository and rebuild, or deploy the archive into a project created without a repository.',
            ['git_repo' => GitUrl::sanitize((string) $user->getGitRepo())]
        );
    }

    public function assertArchive(User $user, string $zipPath): void
    {
        $files = $this->accountFiles($user);
        try {
            UploadedArchive::inHome($zipPath, rtrim($files->homeDirPath(), '/'), $files->resolvedFile(...));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
        }
    }

    /** The home the archive import resolves zip_path against, looked into as the account. */
    protected function accountFiles(User $user): FileManager
    {
        return $this->project($user)->fileManager();
    }

    protected function project(User $user): ProjectAggregate
    {
        return $user->project();
    }

    /**
     * The deploy log this run writes (none on a plain template), opened first so a failure knows its stage and a stream can send its first
     * frame. A git action starts a new deploy as a push does: a `running` log then is a dead process's, never a create waiting for files (no git).
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
     * The log of a deploy this process runs itself, with no task: it names who asked for it.
     *
     * @throws DeployAlreadyRunningException
     */
    public function openLogWithoutTask(User $user, string $action): ?DeployLogger
    {
        $logger = $this->openLog($user, $action);
        DeployRequest::record($logger, $action);

        return $logger;
    }

    /**
     * Run it to the end. The deploy log is finished either way; a failure is thrown in the shape the API answers it in:
     * a ProblemException with a code and the stage, or a refused archive as a 422 on zip_path.
     *
     * @param  array<string, string>  $git  a git action's input
     * @return array<string, mixed> what to add to the task's details
     *
     * @throws ValidationException
     */
    public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath, array $git = [], ?ArchiveSource $archive = null): array
    {
        if ($action === self::DEPLOY_ARCHIVE) {
            $this->deployArchive($user, $logger, $archive ?? ArchiveSource::path((string) $zipPath));

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
     * The git action and the rebuild after it, in this process: the tree the app was deployed from is kept aside only for
     * the process that changes the checkout, and is put back if the new version fails.
     *
     * @param  array<string, string>  $git
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

        return ! $answered && ($e instanceof DeployCancelledException || $logger?->isCancelled() === true);
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

    protected function archives(): ArchiveSources
    {
        return app(ArchiveSources::class);
    }

    /**
     * A DinD rebuild goes through DeploymentWorkflow, PhpHosting only recreates the outer hosting. A failure gets a code and stage;
     * a ValidationException (ProblemException is one) passes through, as does the lock conflict a template rebuild takes here.
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
     * The proxy is re-rendered as a rebuild does it, or an app on another port answers 502 behind a green deploy. An
     * upload or a URL is put into the project first and removed afterwards, whatever happened.
     */
    private function deployArchive(User $user, ?DeployLogger $logger, ArchiveSource $archive): void
    {
        $zipPath = null;
        try {
            $zipPath = $this->archives()->materialise($user, $archive);
            $this->project($user)->deployment()->deployFromArchive($logger, $zipPath);
            $this->project($user)->system()->webserver()->rebuildDomains();
            self::recordSucceeded($user);
        } catch (DeployCancelledException $e) {
            $stage = $logger?->currentStage();
            $logger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (\InvalidArgumentException $e) {
            $logger?->finishFailed(DeployRefusal::findingOf($e, DeployFailureExplainer::ARCHIVE_REFUSED));
            throw ValidationException::withMessages([
                'zip_path' => $e->getMessage(),
            ]);
        } catch (ProblemException $e) {
            $logger?->finishFailed(new Finding($e->problems[0]['code'] ?? DeployFailureExplainer::DEPLOY_FAILED, $e->getMessage()));
            throw $e;
        } catch (\Exception $e) {
            throw self::archiveFailure($e, $logger);
        } finally {
            if ($zipPath !== null) {
                $this->archives()->release($user, $archive, $zipPath);
            }
        }
    }

    private static function archiveFailure(\Exception $e, ?DeployLogger $logger): ProblemException
    {
        $logger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
        $failure = DeployFailureExplainer::failure($e, $match, DeployFailureExplainer::DEPLOY_FAILED, $message)->withMessage($message);
        $stage = $logger?->currentStage();
        $logger?->finishFailed($failure);

        return ProblemException::deploy($failure->code, $message, $stage);
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
        $failure = DeployFailureExplainer::failure($e, $match, DeployFailureExplainer::REBUILD_FAILED, $message)->withMessage($message);
        $stage = $logger?->currentStage();
        self::finishRebuildLog($logger, DeployLogger::STATUS_FAILED, $message, $failure);

        return ProblemException::deploy($failure->code, $message, $stage);
    }

    /**
     * The workflow finishes the log itself when a rebuild fails or is cancelled, and a second finish would report it twice.
     * A cancel request alone sets the status without finishing, so `finished_at` is what says it was done.
     */
    private static function finishRebuildLog(?DeployLogger $logger, string $status, string $error, ?Finding $failure = null): void
    {
        if ($logger === null) {
            return;
        }
        $latest = $logger->readLatest() ?? [];
        $settled = in_array($latest['status'] ?? null, [DeployLogger::STATUS_FAILED, DeployLogger::STATUS_CANCELLED], true);
        if ($settled && ($latest['finished_at'] ?? null) !== null) {
            return;
        }
        $failure !== null ? $logger->finishFailed($failure) : $logger->finish($status, $error);
    }

    /**
     * A rebuild that worked is a deploy that worked: the status decides the entrypoint's install/upgrade phase
     * ({@see PlatformStage::phaseFor()}), so an account that was only ever rebuilt must not keep running its first install.
     */
    public static function recordSucceeded(User $user): void
    {
        $warnings = self::servingWarnings($user);
        if ($warnings !== []) {
            $user->setDetails([
                'deployment_status' => 'partial',
                'deployment_warnings' => Finding::storable($warnings),
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
     * The health and public-URL verdicts the pipeline finishes its log with, applied to the account record only: a rebuild
     * does not run the pipeline, and {@see DeploymentWorkflow::rebuildFromSource()} finishes the log.
     *
     * @return list<Finding>
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
            && ! $user->isAppStoppedByRequest();
    }
}
