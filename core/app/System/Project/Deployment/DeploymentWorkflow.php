<?php

namespace App\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Http\Controllers\UserController;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStartLine;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind as DindRuntime;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use Illuminate\Support\Facades\Log;

/**
 * DinD-from-source Deploy orchestration on the App\System path (ADR-0003).
 * Provision stays on the DinD Project implementation; failed Deploy retains the Project.
 */
final class DeploymentWorkflow
{
    public function __construct(
        private ?DeployableDindProject $project = null,
        private ?DeployMechanics $mechanics = null,
        private ?FailureDisposition $disposition = null,
        private bool $explainNoiseOnlyOutput = true,
    ) {}

    /**
     * A workflow over mechanics that are not the DinD default. $explainNoiseOnlyOutput false keeps the template path's
     * reporting: an all-noise start failure is reported as it stands, where DinD asks the explainer about all of it.
     */
    public static function forMechanics(
        DeployMechanics $mechanics,
        FailureDisposition $disposition,
        bool $explainNoiseOnlyOutput = true,
    ): self {
        return new self(null, $mechanics, $disposition, $explainNoiseOnlyOutput);
    }

    /** DinD keeps a failed project; the template path rolls it back. */
    private function disposition(): FailureDisposition
    {
        return $this->disposition ??= new RetainProject;
    }

    /**
     * A project whose account a failed prepare removed again has nothing left to keep:
     * it is rolled back as on the template path, so no project is left without an account.
     */
    private function dispositionFor(DeployMechanics $mechanics): FailureDisposition
    {
        return $mechanics->removedTheAccount() ? new RollBackProject : $this->disposition();
    }

    public function run(?DeployLogger $deployLogger = null, ?callable $beforeRetention = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();
        $domain = $mechanics->requireMainDomain();

        if (! $this->prepareForRun($mechanics, $user, $domain, $deployLogger, $beforeRetention)) {
            return;
        }

        $warnings = [];
        $failures = [];
        $failureOutputs = [];

        if ($mechanics->hasGitProject() || $user->getTemplate() === 'dind') {
            $this->startForRun($mechanics, $user, $deployLogger, $beforeRetention, $failures, $failureOutputs);
        }

        if ($failures !== []) {
            $this->failRunStart($mechanics, $user, $deployLogger, $beforeRetention, $warnings, $failures, $failureOutputs);
        }

        $this->finishServing($mechanics, $domain, $deployLogger);
    }

    /** False when the account waits for its files. */
    private function prepareForRun(DeployMechanics $mechanics, ModelsUser $user, DomainModel $domain, ?DeployLogger $deployLogger, ?callable $beforeRetention): bool
    {
        try {
            $deployLogger?->stage(DeployLogger::STAGE_PREPARING);
            $mechanics->prepareHostingEnvironment();

            if ($mechanics->isDindWithoutGit()) {
                $mechanics->publishDomain($domain);
                $deployLogger?->throwIfCancelled();
                $deployLogger?->waitForFiles();

                return false;
            }

            $deployLogger?->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestApplicationSource();
            $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
            $mechanics->publishDomain($domain);
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username, cancelled: true);
            $this->dispositionFor($mechanics)->afterCancelled($user, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (\Exception $e) {
            $this->failPreparing($mechanics, $user, $deployLogger, $beforeRetention, $e);
        }

        return true;
    }

    private function failPreparing(DeployMechanics $mechanics, ModelsUser $user, ?DeployLogger $deployLogger, ?callable $beforeRetention, \Exception $e): never
    {
        $deployLogger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? $e->getMessage();
        $hint = $mechanics->customEnvFailureHint();
        if ($hint !== null) {
            $message .= ' | '.$hint;
        }
        $failure = DeployFailureExplainer::failure($e, $match, DeployFailureExplainer::DEPLOY_FAILED, $message)->withMessage($message);
        $stage = $deployLogger?->currentStage();
        $deployLogger?->finishFailed($failure);
        $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
        $this->dispositionFor($mechanics)->afterFailure($user, $message);
        throw ProblemException::deploy($failure->code, $message, $stage);
    }

    /**
     * A failed start of a first deploy takes the volumes down with it: they hold only what this failed start wrote.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $failureOutputs
     */
    private function startForRun(DeployMechanics $mechanics, ModelsUser $user, ?DeployLogger $deployLogger, ?callable $beforeRetention, array &$failures, array &$failureOutputs): void
    {
        try {
            $result = $mechanics->startApplication();
            $deployLogger?->throwIfCancelled();
            if ($result['exit_code'] !== 0) {
                try {
                    $mechanics->abortPartialDeploy(removeVolumes: true);
                } catch (\Exception $cleanup) {
                    Log::warning(
                        "Partial deploy cleanup failed for {$user->username}: {$cleanup->getMessage()}",
                    );
                }
                $output = $result['stderr'] ?: $result['stdout'];
                $failureOutputs[] = $output;
                $failures[] = $this->startFailureMessage($output, $deployLogger);
            }
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username, cancelled: true);
            $this->disposition()->afterCancelled($user, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (\Exception $e) {
            $failureOutputs[] = $e->getMessage();
            $failures[] = $this->startFailureMessage($e->getMessage(), $deployLogger);
        }
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $failures
     * @param  list<string>  $failureOutputs
     */
    private function failRunStart(DeployMechanics $mechanics, ModelsUser $user, ?DeployLogger $deployLogger, ?callable $beforeRetention, array $warnings, array $failures, array $failureOutputs): never
    {
        $hint = $mechanics->customEnvFailureHint();
        if ($hint !== null) {
            $warnings[] = $hint;
        }
        $messages = array_merge($failures, $warnings);
        $summary = implode(' | ', $messages);
        $match = DeployFailureExplainer::match(implode("\n", $failureOutputs));
        $failure = DeployFailureExplainer::failure($summary, $match, DeployFailureExplainer::APP_DID_NOT_START, $summary);
        $stage = $deployLogger?->currentStage();
        $deployLogger?->finishFailed($failure);
        $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
        $this->disposition()->afterFailure($user, $summary);
        throw ProblemException::deploy($failure->code, $summary, $stage);
    }

    private function finishServing(DeployMechanics $mechanics, DomainModel $domain, ?DeployLogger $deployLogger): void
    {
        $serving = $mechanics->servingWarnings();
        $warnings = [...$serving, ...$mechanics->publicUrlWarnings($domain)];
        self::logDomainNotices($deployLogger, $mechanics->user());

        if ($warnings === []) {
            $mechanics->persistSuccess();
            $deployLogger?->finish(DeployLogger::STATUS_SUCCESS);

            return;
        }

        $warnings = [...$warnings, ...self::customEnvSuspicion($mechanics, $serving)];
        $mechanics->persistPartialSuccess($warnings);
        $deployLogger?->finishPartial($warnings);
    }

    /**
     * Only an app that is not serving can have been broken by its variables, not a public name or certificate warning.
     *
     * @param  list<Finding>  $serving
     * @return list<Finding>
     */
    private static function customEnvSuspicion(DeployMechanics $mechanics, array $serving): array
    {
        $hint = $serving !== [] ? $mechanics->customEnvFailureHint() : null;

        return $hint !== null ? [new Finding(DeployMechanics::CUSTOM_ENV_SUSPECTED, $hint)] : [];
    }

    /**
     * Redeploy from files already in ~/project (git pull/revert/change-branch on deploy-managed accounts).
     *
     * @param  string  $source  what the deploy log says started this: `git` for a manual porcelain call,
     *                          `push` for a Deploy Hook delivery
     * @param  ?string  $commit  the commit the checkout is at, named in the log when the caller knows it
     * @param  ?string  $branch  the branch the checkout is on, named in the log when the caller knows it
     */
    public function rebuildFromCheckout(?DeployLogger $deployLogger = null, string $source = 'git', ?string $commit = null, ?string $branch = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();

        if ($deployLogger === null) {
            $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
        }
        $deployLogger?->info(DeployStartLine::text($source, ['commit' => $commit, 'branch' => $branch]));

        $succeeded = false;
        try {
            $this->failingTheCheckoutDeploy($user, $deployLogger, fn () => $this->redeployCheckout($mechanics, $user, $deployLogger));
            $succeeded = true;
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
    }

    /**
     * Starts a stopped `dind` account, or repairs one sysbox forgot, before its checkout changes: git runs in the
     * account's container. A failed start or repair fails the deploy with its own error.
     *
     * @throws ProblemException
     */
    public function startAccountForCheckout(?DeployLogger $deployLogger = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();
        if ($user->getTemplate() !== 'dind') {
            return;
        }

        $this->failingTheCheckoutDeploy($user, $deployLogger, function () use ($mechanics, $deployLogger): void {
            if ($mechanics->isApplicationEnvironmentRunning()) {
                $mechanics->repairAccountMapping();
            } else {
                $this->startAccount($mechanics, $deployLogger);
            }
        });
    }

    /**
     * A cancel or a failure in a step of a deploy from the checkout finishes that deploy, a failure as the explainer
     * reads it.
     *
     * @throws ProblemException
     */
    private function failingTheCheckoutDeploy(ModelsUser $user, ?DeployLogger $deployLogger, callable $step): void
    {
        try {
            $step();
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->disposition()->afterCancelled($user, $e->getMessage());
            throw ProblemException::deploy('deploy_cancelled', $e->getMessage(), $stage);
        } catch (ProblemException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->failCheckoutRedeploy($user, $deployLogger, $e);
        }
    }

    private function redeployCheckout(DeployMechanics $mechanics, ModelsUser $user, ?DeployLogger $deployLogger): void
    {
        $mechanics->recordRunningVersion();
        if ($user->getTemplate() === 'dind' && $mechanics->isApplicationEnvironmentRunning()) {
            $mechanics->syncHostingForCheckoutRebuild(true);
        } else {
            $this->startAccount($mechanics, $deployLogger);
        }
        $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
        $mechanics->reprepareApplicationFromCheckout();

        $domain = $mechanics->requireMainDomain();
        $result = $mechanics->startApplication();
        $deployLogger?->throwIfCancelled();
        if ($result['exit_code'] !== 0) {
            $this->failCheckoutStart($mechanics, $user, $deployLogger, $result);
        }

        $this->finishServing($mechanics, $domain, $deployLogger);
    }

    /** The account set up again from its template and started, for a checkout to build from. */
    private function startAccount(DeployMechanics $mechanics, ?DeployLogger $deployLogger): void
    {
        $deployLogger?->stage(DeployLogger::STAGE_PREPARING);
        $deployLogger?->dim('Recreating home and project directories');
        $mechanics->syncHostingForCheckoutRebuild(false);
    }

    /**
     * Neither a failed release, which started nothing new, nor a kept version is torn down: that would only stop the
     * version still serving.
     */
    private function failCheckoutStart(DeployMechanics $mechanics, ModelsUser $user, ?DeployLogger $deployLogger, array $result): never
    {
        if (! empty($result[AppLauncher::RELEASE_FAILED])) {
            $deployLogger?->info(self::releaseFailedLine($result));
        } elseif (($serving = ZeroDowntimeRedeploy::keptServing($result)) !== null) {
            $deployLogger?->info($serving);
        } else {
            try {
                $mechanics->abortPartialDeploy();
            } catch (\Exception $cleanup) {
                Log::warning(
                    "Partial deploy cleanup failed for {$user->username}: {$cleanup->getMessage()}",
                );
            }
        }
        $output = $result['stderr'] ?: $result['stdout'];
        $message = $this->startFailureMessage($output, $deployLogger);
        $failure = DeployFailureExplainer::failure($output, DeployFailureExplainer::match($output), DeployFailureExplainer::APP_DID_NOT_START, $message);
        $deployLogger?->finishFailed($failure);
        $this->disposition()->afterFailure($user, $message);
        throw ProblemException::deploy($failure->code, $message, $deployLogger?->currentStage());
    }

    private function failCheckoutRedeploy(ModelsUser $user, ?DeployLogger $deployLogger, \Exception $e): never
    {
        $deployLogger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? $e->getMessage();
        $failure = DeployFailureExplainer::failure($e, $match, DeployFailureExplainer::DEPLOY_FAILED, $message)->withMessage($message);
        $deployLogger?->finishFailed($failure);
        $this->disposition()->afterFailure($user, $message);
        throw ProblemException::deploy($failure->code, $message, $deployLogger?->currentStage());
    }

    /**
     * Wipe-and-redeploy from git or zip (HTTP/artisan rebuild path). Finishes the deploy log and records a failure on
     * the project; success is persisted by callers such as ProjectRebuild::recordSucceeded().
     *
     * @throws ProblemException a start failure, carrying the explainer's rule
     * @throws \Exception anything else
     */
    public function rebuildFromSource(?DeployLogger $deployLogger = null, ?string $zipPath = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();

        if ($deployLogger === null) {
            $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
        }
        self::refuseDeletedProject($user, $deployLogger);
        $this->logSourceRebuildStart($deployLogger);

        $succeeded = false;
        try {
            $this->prepareSourceRebuild($mechanics, $deployLogger, $zipPath);

            $this->startAndFinishSourceDeploy($deployLogger, $mechanics);
            $succeeded = true;
        } catch (DeployCancelledException $e) {
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            FailureRetention::retainAfterDeployCancelled($user, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $failure = self::unexpectedFailure($e, $deployLogger, DeployFailureExplainer::REBUILD_FAILED);
            $deployLogger?->finishFailed($failure);
            FailureRetention::retainAfterDeployFailure($user, $failure->message);
            throw $e;
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
    }

    /**
     * Asked with the deploy lock held, which a delete keeps until the row is gone, so a model read before a delete
     * never rebuilds the account it removed. The log just opened for it goes too, unless the name is taken again.
     *
     * @throws NotFoundException
     */
    private static function refuseDeletedProject(ModelsUser $user, ?DeployLogger $deployLogger): void
    {
        try {
            $user->assertNotDeletedElsewhere();
        } catch (NotFoundException $e) {
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage().' Nothing was rebuilt.');
            if (! ModelsUser::existsByUsername($user->username)) {
                DeployLogger::deleteUserLogs($user->username);
            }
            throw $e;
        }
    }

    /** A failure the pipeline did not explain itself: the explainer's sentence when it has one, the output otherwise. */
    private static function unexpectedFailure(\Exception $e, ?DeployLogger $deployLogger, string $fallback): Finding
    {
        $deployLogger?->recordFailureOutput($e->getMessage());
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());

        return DeployFailureExplainer::failure($e, $match, $fallback, $message)->withMessage($message);
    }

    private function logSourceRebuildStart(?DeployLogger $deployLogger): void
    {
        if ($deployLogger === null) {
            return;
        }
        $latest = $deployLogger->readLatest();
        $continuing = $deployLogger->isRunning()
            && (($latest['stage'] ?? null) === DeployLogger::STAGE_PREPARING);
        if ($continuing) {
            $deployLogger->info('Continuing deploy with project files');
        } else {
            $deployLogger->info('Deploy started (source: rebuild)');
        }
    }

    private function prepareSourceRebuild(DeployMechanics $mechanics, ?DeployLogger $deployLogger, ?string $zipPath): void
    {
        $mechanics->recordRunningVersion();
        $reuseRunning = $mechanics->isApplicationEnvironmentRunning();
        if (! $reuseRunning) {
            $deployLogger?->stage(DeployLogger::STAGE_PREPARING);
            $deployLogger?->dim('Recreating home and project directories');
        }
        if ($zipPath !== null && $zipPath !== '') {
            $deployLogger?->info('Importing project archive before rebuild');
        }
        if (! $reuseRunning) {
            $deployLogger?->dim('Preparing container environment');
            $deployLogger?->dim('Restarting container environment');
        }
        $mechanics->syncHostingForSourceRebuild($zipPath);

        $deployLogger?->stage(DeployLogger::STAGE_CLONING);
        $mechanics->ingestForWipeRebuild($zipPath);
    }

    /**
     * Like {@see rebuildFromSource()}, but the archive is deployed as it stands: ~/project, which normally holds it, is
     * not wiped. A failure or a cancel closes the log before the settle, which needs the deploy lock the log holds.
     *
     * @throws \InvalidArgumentException a refused archive, with nothing replaced (the caller answers 422 on zip_path)
     * @throws ProblemException a start failure
     * @throws \Exception anything else, for the caller to map
     */
    public function deployFromArchive(?DeployLogger $deployLogger, string $zipPath): void
    {
        $mechanics = $this->resolveMechanics();

        $succeeded = false;
        try {
            $mechanics->recordRunningVersion();
            $deployLogger?->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestArchive($zipPath);

            $this->startAndFinishSourceDeploy($deployLogger, $mechanics);
            $succeeded = true;
        } catch (\InvalidArgumentException $e) {
            $deployLogger?->finishFailed(DeployRefusal::findingOf($e, DeployFailureExplainer::ARCHIVE_REFUSED));
            throw $e;
        } catch (DeployCancelledException $e) {
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            FailureRetention::retainAfterDeployCancelled($mechanics->user(), $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $failure = self::unexpectedFailure($e, $deployLogger, DeployFailureExplainer::DEPLOY_FAILED);
            $deployLogger?->finishFailed($failure);
            FailureRetention::retainAfterDeployFailure($mechanics->user(), $failure->message);
            throw $e;
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
    }

    /**
     * Start and finish a source rebuild or an archive deploy; a kept version is said as a checkout redeploy says it.
     * The rule is matched on the output, as telemetry does: the thrown sentence does not carry what a rule matches.
     */
    private function startAndFinishSourceDeploy(?DeployLogger $deployLogger, DeployMechanics $mechanics): void
    {
        $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
        $result = $mechanics->startApplication();
        $deployLogger?->throwIfCancelled();
        if ($result['exit_code'] !== 0) {
            if (! empty($result[AppLauncher::RELEASE_FAILED])) {
                $deployLogger?->info(self::releaseFailedLine($result));
            } elseif (($serving = ZeroDowntimeRedeploy::keptServing($result)) !== null) {
                $deployLogger?->info($serving);
            }
            $raw = $result['stderr'] ?: $result['stdout'];
            $deployLogger?->recordFailureOutput($raw);
            $message = $this->failureSentence($raw);
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $deployLogger?->info($hint);
            }
            $full = self::startFailure($message).($hint !== null ? " | {$hint}" : '');
            $region = trim(FailureOutput::select($raw));
            $match = ($region !== '' ? DeployFailureExplainer::match($region) : null) ?? DeployFailureExplainer::match($raw);
            $failure = DeployFailureExplainer::failure($raw, $match, DeployFailureExplainer::APP_DID_NOT_START, $full);
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finishFailed($failure);
            throw ProblemException::deploy($failure->code, $full, $stage);
        }

        $this->finishSourceRebuildServing($deployLogger, $mechanics);
    }

    /** "Left as it was" only when something was running: a first deploy had nothing to leave. */
    private static function releaseFailedLine(array $result): string
    {
        return match ($result[AppLauncher::RAN_BEFORE_RELEASE] ?? null) {
            true => 'The release failed before the new version started; the running app was left as it was',
            false => 'The release failed before the new version started; no version of the app is running',
            default => 'The release failed before the new version started',
        };
    }

    private function finishSourceRebuildServing(?DeployLogger $logger, DeployMechanics $mechanics): void
    {
        $user = $mechanics->user();
        $warnings = [
            ...$mechanics->servingWarnings(),
            ...PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        ];
        self::logDomainNotices($logger, $user);

        $warnings === []
            ? $logger?->finish(DeployLogger::STATUS_SUCCESS)
            : $logger?->finishPartial($warnings);
    }

    /** A fallback domain that still works: said in the log, not a warning. */
    private static function logDomainNotices(?DeployLogger $logger, $user): void
    {
        foreach (PublicUrl::notices((string) $user->domain, $user->getDetails()) as $notice) {
            $logger?->warn($notice);
        }
    }

    private function resolveMechanics(): DeployMechanics
    {
        if ($this->mechanics !== null) {
            return $this->mechanics;
        }
        if (! $this->project instanceof DindRuntime) {
            throw new \LogicException('Default deploy mechanics require a DinD runtime project.');
        }

        return new DindDeployMechanics($this->project);
    }

    /**
     * A build writes a layer banner per step and megabytes of log, and the explainer matches anywhere in it: narrow to
     * the failing region first, or the sentence names the banner printed before anything broke.
     */
    private function startFailureMessage(string $output, ?DeployLogger $logger = null): string
    {
        $logger?->recordFailureOutput($output);

        return self::startFailure($this->failureSentence($output));
    }

    /** A new version the switch refused for an empty page did start: only the sentence is said. */
    private static function startFailure(string $sentence): string
    {
        return str_starts_with($sentence, DeployFailureExplainer::EMPTY_NEW_VERSION) ? $sentence : "Failed to start app: {$sentence}";
    }

    /**
     * The explainer's sentence, else the failing region, as the first deploy reports it ({@see UserController}):
     * the whole output of a compose up is mostly pull progress.
     */
    private function failureSentence(string $output): string
    {
        $region = trim(FailureOutput::select($output));
        if ($region === '' && ! $this->explainNoiseOnlyOutput) {
            return trim($output);
        }
        $explanation = DeployFailureExplainer::explain($region !== '' ? $region : $output);
        if ($explanation !== null) {
            return $explanation;
        }

        return $region !== '' ? $region : trim($output);
    }

    private function invokeBeforeRetention(
        ?callable $beforeRetention,
        ?DeployLogger $logger,
        string $username,
        bool $cancelled = false,
    ): void {
        if ($beforeRetention === null || $logger === null) {
            return;
        }
        if ($cancelled && ! $this->disposition()->hookRunsOnCancel()) {
            return;
        }
        try {
            $beforeRetention($logger);
        } catch (\Throwable $e) {
            Log::warning('Before-'.$this->disposition()->hookName()." hook failed for {$username}: {$e->getMessage()}");
        }
    }
}
