<?php

namespace App\Lib\Project;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\LogStorage;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Lib\Domains\PublicUrl;
use App\Models\Task;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\AppHealth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Deploying a project that already exists: a rebuild from ~/project (after
 * importing an archive when one is given), or an archive deployed into it.
 *
 * One body for both ways of running it -- the RebuildProject job behind the
 * 202, and the NDJSON stream a client can still ask for -- so the two cannot
 * answer differently.
 */
class ProjectRebuild
{
    public const REBUILD = 'rebuild';

    public const DEPLOY_ARCHIVE = 'deploy_archive';

    /**
     * Queue the run and return its task.
     */
    public function queue(User $user, string $action, ?string $zipPath, ?DeployPlan $plan, ?string $recipe): Task
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
            ], static fn (mixed $v): bool => $v !== null),
        );
        RebuildProject::dispatch($user->username, $action, $zipPath, $plan?->toArray(), $recipe)->attachTask($task);

        return $task;
    }

    /**
     * The deploy of this project that is queued or running: a create still
     * deploying, a rebuild or an archive deploy.
     */
    public static function pendingTask(string $username): ?Task
    {
        return Task::query()
            ->where('username', $username)
            ->whereIn('job_type', Task::DEPLOY_JOB_TYPES)
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
     * beside it.
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
            // Going on unlocked could queue a second deploy beside a racing one.
            throw new ServiceUnavailableHttpException(null, 'Could not lock the project to start its deploy; try again.');
        }
        try {
            $task = self::pendingTask($user->username);
            if ($task !== null || DeployLogger::isLockedFor($user->username)) {
                throw new DeployBusyException($user->username, $task);
            }

            return $start();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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
     *
     * @throws DeployAlreadyRunningException
     */
    public function openLog(User $user, string $action): ?DeployLogger
    {
        if ($action === self::REBUILD && $user->getTemplate() !== 'dind') {
            return null;
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
     * @throws ValidationException
     */
    public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath): void
    {
        if ($action === self::DEPLOY_ARCHIVE) {
            $this->deployArchive($user, $logger, (string) $zipPath);

            return;
        }

        $this->rebuild($user, $logger, $zipPath);
    }

    /**
     * A rebuild whose compose dependency failed used to answer
     * `500 {"message":"Server Error"}`: no code, no stage, no offset, while
     * the deploy log for the same run had the real error in it.
     */
    private function rebuild(User $user, ?DeployLogger $logger, ?string $zipPath): void
    {
        try {
            // DinD wipe-rebuild goes through DeploymentWorkflow; PhpHosting only recreates outer hosting.
            $this->project($user)->rebuildFromSource($logger, $zipPath);
            $this->project($user)->system()->webserver()->rebuildDomains();
            self::recordSucceeded($user);
        } catch (DeployCancelledException $e) {
            $stage = $logger?->currentStage();
            self::finishRebuildLog($logger, DeployLogger::STATUS_CANCELLED, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (ValidationException $e) {
            // ProblemException is one of these, so anything already in the
            // documented shape passes through rather than being re-wrapped.
            throw $e;
        } catch (DeployAlreadyRunningException $e) {
            // A template rebuild takes the account's lock in here, not in openLog(): still a lock conflict.
            throw $e;
        } catch (\Exception $e) {
            throw self::rebuildFailure($e, $logger);
        }
    }

    private function deployArchive(User $user, ?DeployLogger $logger, string $zipPath): void
    {
        try {
            $this->project($user)->deployment()->deployFromArchive($logger, $zipPath);
            // The vhosts were rendered when the account was created, against
            // the welcome app's port. Detection has just re-pointed app_port
            // at what the archive really serves on (8000 for PHP, 3000 for
            // Express, ...), so the proxy must be re-rendered the same way a
            // rebuild does it -- or every non-8080 app answers 502 behind a
            // green deploy.
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
            // A start failure already carries its rule; finish() is a no-op once finished.
            $logger?->finish(DeployLogger::STATUS_FAILED, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $logger?->recordFailureOutput($e->getMessage());
            $match = DeployFailureExplainer::match($e->getMessage());
            $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
            $stage = $logger?->currentStage();
            $logger?->finish(DeployLogger::STATUS_FAILED, $message);
            throw ProblemException::deploy($match['rule'] ?? 'deploy_failed', $message, $stage);
        }
    }

    /**
     * A failed rebuild, in the shape every other deploy endpoint answers in.
     *
     * Its own method so it can be exercised without a request: the defect was
     * that this translation did not exist at all, and a test that has to stand
     * up a controller to see it would not have caught that either.
     */
    private static function rebuildFailure(\Exception $e, ?DeployLogger $logger): ProblemException
    {
        $logger?->recordFailureOutput($e->getMessage());
        // The same slug deploy telemetry reports, so a client and a dashboard
        // name one failure the same way.
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
        $stage = $logger?->currentStage();
        self::finishRebuildLog($logger, DeployLogger::STATUS_FAILED, $message);

        return ProblemException::deploy($match['rule'] ?? 'rebuild_failed', $message, $stage);
    }

    /**
     * The workflow finishes the log itself when a rebuild fails or is cancelled.
     * Finishing it again wrote a second "Deploy failed" line and filed a second
     * telemetry report for the same rebuild. A cancel request alone sets the
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
     * A rebuild that worked is a deploy that worked, and the next one needs to
     * know that.
     *
     * The deployment status is what the entrypoint's install/upgrade phase is
     * derived from ({@see PlatformStage::phaseFor()}), and rebuild used to
     * leave it untouched. An account that had only ever been rebuilt was
     * therefore stuck reporting a first install for the rest of its life:
     * every rebuild re-ran `php artisan key:generate --force`, throwing away
     * the APP_KEY that every session cookie and encrypted column depends on,
     * and re-ran the seeders behind it. The whole point of splitting install
     * from upgrade is that install runs once.
     */
    public static function recordSucceeded(User $user): void
    {
        // A rebuild does not run the deploy pipeline, so the verdict the
        // health checks reached has to be applied here as well -- and it is
        // applied first, because "the site is serving our placeholder" is
        // true whether this account had ever deployed successfully before or
        // not.
        //
        // The account record only. The deploy log -- and telemetry with it --
        // is finished by the pipeline itself, which reaches the same verdict
        // through the same helper ({@see \App\System\Project\Deployment\DeploymentWorkflow::rebuildFromSource()}).
        // Doing it here as well would file a second terminal status for one
        // rebuild and report it twice.
        //
        // Both lists the pipeline finishes the log with: with the health one
        // alone, a rebuild logged `partial` for its public URL and recorded `success`.
        $warnings = array_merge(
            AppHealth::servingWarnings($user->getDetails()),
            PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        );
        if ($warnings !== []) {
            $user->setDetails([
                'deployment_status' => 'partial',
                'deployment_warnings' => $warnings,
                'error' => null,
            ]);
            $user->save();

            return;
        }

        // A clean rebuild clears a previous run's warnings: leaving them would
        // report a fault that has since been fixed.
        if ($user->getDeploymentStatus() === 'success' && ($user->getDetails()['deployment_warnings'] ?? []) === []) {
            return;
        }
        $user->markDeploySucceeded();
        $user->save();
    }
}
