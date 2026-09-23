<?php

namespace App\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\PublicUrl;
use App\System\Project\Dind as DindRuntime;
use Illuminate\Support\Facades\Log;

/**
 * DinD-from-source Deploy orchestration on the App\System path (ADR-0003).
 * Provision stays on the DinD Project implementation; failed Deploy retains the Project.
 */
final class DeploymentWorkflow
{
    public function __construct(
        private DeployableDindProject $project,
        private ?DeployMechanics $mechanics = null,
    ) {
    }

    public function run(?DeployLogger $deployLogger = null, ?callable $beforeRetention = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();
        $domain = $mechanics->requireMainDomain();

        try {
            $deployLogger?->stage(DeployLogger::STAGE_PREPARING);
            $mechanics->prepareHostingEnvironment();

            if ($mechanics->isDindWithoutGit()) {
                $mechanics->publishDomain($domain);
                $deployLogger?->info('Environment ready, waiting for project files');

                return;
            }

            $deployLogger?->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestApplicationSource();
            $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
            $mechanics->publishDomain($domain);
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
            FailureRetention::retainAfterDeployCancelled($user, $e->getMessage());
            throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
        } catch (\Exception $e) {
            $deployLogger?->recordFailureOutput($e->getMessage());
            $match = DeployFailureExplainer::match($e->getMessage());
            $message = $match['message'] ?? $e->getMessage();
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $message .= ' | ' . $hint;
            }
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_FAILED, $message);
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
            FailureRetention::retainAfterDeployFailure($user, $message);
            throw self::deployProblem($match['rule'] ?? 'deploy_failed', $message, $stage);
        }

        $warnings = [];
        $failures = [];
        $failureOutputs = [];

        if ($mechanics->hasGitProject() || $user->getTemplate() === 'dind') {
            try {
                $result = $mechanics->startApplication();
                if ($result['exit_code'] !== 0) {
                    try {
                        $mechanics->abortPartialDeploy();
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
                $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
                FailureRetention::retainAfterDeployCancelled($user, $e->getMessage());
                throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
            } catch (\Exception $e) {
                $failureOutputs[] = $e->getMessage();
                $failures[] = $this->startFailureMessage($e->getMessage(), $deployLogger);
            }
        }

        if ($failures !== []) {
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $warnings[] = $hint;
            }
            $messages = array_merge($failures, $warnings);
            $summary = implode(' | ', $messages);
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_FAILED, $summary);
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username);
            FailureRetention::retainAfterDeployFailure($user, $summary);
            $match = DeployFailureExplainer::match(implode("\n", $failureOutputs));
            throw self::deployProblem($match['rule'] ?? 'app_did_not_start', $summary, $stage);
        }

        $warnings = array_merge($warnings, $mechanics->servingWarnings());
        $warnings = array_merge($warnings, $mechanics->publicUrlWarnings($domain));

        if ($warnings !== []) {
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $warnings[] = $hint;
            }
            $mechanics->persistPartialSuccess($warnings);
            $deployLogger?->finish(
                DeployLogger::STATUS_PARTIAL,
                implode(' | ', $warnings),
            );
        } else {
            $mechanics->persistSuccess();
            $deployLogger?->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    /**
     * Redeploy from files already in ~/project (git pull/revert/change-branch on deploy-managed accounts).
     *
     * @param string  $source what the deploy log says started this: `git` for a manual porcelain call,
     *                        `push` for a Deploy Hook delivery
     * @param ?string $commit the commit the checkout is at, named in the log when the caller knows it
     */
    public function rebuildFromCheckout(?DeployLogger $deployLogger = null, string $source = 'git', ?string $commit = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();

        if ($deployLogger === null) {
            $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
        }
        $deployLogger->info('Deploy started (source: ' . $source . ($commit !== null && $commit !== '' ? ', commit: ' . $commit : '') . ')');

        try {
            $reuseRunning = $user->getTemplate() === 'dind' && $mechanics->isApplicationEnvironmentRunning();
            if (!$reuseRunning) {
                $deployLogger->stage(DeployLogger::STAGE_PREPARING);
                $deployLogger->dim('Recreating home and project directories');
            }
            $mechanics->syncHostingForCheckoutRebuild($reuseRunning);
            $deployLogger->stage(DeployLogger::STAGE_RUNNING);
            $mechanics->reprepareApplicationFromCheckout();

            $domain = $mechanics->requireMainDomain();
            $result = $mechanics->startApplication();
            if ($result['exit_code'] !== 0) {
                try {
                    $mechanics->abortPartialDeploy();
                } catch (\Exception $cleanup) {
                    Log::warning(
                        "Partial deploy cleanup failed for {$user->username}: {$cleanup->getMessage()}",
                    );
                }
                $output = $result['stderr'] ?: $result['stdout'];
                $message = $this->startFailureMessage($output, $deployLogger);
                $deployLogger->finish(DeployLogger::STATUS_FAILED, $message);
                FailureRetention::retainAfterDeployFailure($user, $message);
                $match = DeployFailureExplainer::match($output);
                throw self::deployProblem($match['rule'] ?? 'app_did_not_start', $message, $deployLogger->currentStage());
            }

            $warnings = array_merge(
                $mechanics->servingWarnings(),
                $mechanics->publicUrlWarnings($domain),
            );
            if ($warnings !== []) {
                $hint = $mechanics->customEnvFailureHint();
                if ($hint !== null) {
                    $warnings[] = $hint;
                }
                $mechanics->persistPartialSuccess($warnings);
                $deployLogger->finish(DeployLogger::STATUS_PARTIAL, implode(' | ', $warnings));
            } else {
                $mechanics->persistSuccess();
                $deployLogger->finish(DeployLogger::STATUS_SUCCESS);
            }
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger->currentStage();
            $deployLogger->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            FailureRetention::retainAfterDeployCancelled($user, $e->getMessage());
            throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
        } catch (ProblemException $e) {
            throw $e;
        } catch (\Exception $e) {
            $deployLogger->recordFailureOutput($e->getMessage());
            $match = DeployFailureExplainer::match($e->getMessage());
            $message = $match['message'] ?? $e->getMessage();
            $deployLogger->finish(DeployLogger::STATUS_FAILED, $message);
            FailureRetention::retainAfterDeployFailure($user, $message);
            throw self::deployProblem($match['rule'] ?? 'deploy_failed', $message, $deployLogger->currentStage());
        }
    }

    /**
     * Wipe-and-redeploy from git or zip (HTTP/artisan rebuild path).
     *
     * Finishes the deploy log but does not persist deployment_status — callers
     * such as UserController::recordRebuildSucceeded own that.
     * Throws plain \Exception (not ProblemException / FailureRetention) to
     * match the historical HTTP rebuild contract.
     */
    public function rebuildFromSource(?DeployLogger $deployLogger = null, ?string $zipPath = null): void
    {
        $mechanics = $this->resolveMechanics();
        $user = $mechanics->user();

        if ($deployLogger === null) {
            $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
        }
        $latest = $deployLogger->readLatest();
        $continuing = $deployLogger->isRunning()
            && (($latest['stage'] ?? null) === DeployLogger::STAGE_PREPARING);
        if ($continuing) {
            $deployLogger->info('Continuing deploy with project files');
        } else {
            $deployLogger->info('Deploy started (source: rebuild)');
        }

        try {
            $reuseRunning = $mechanics->isApplicationEnvironmentRunning();
            if (!$reuseRunning) {
                $deployLogger->stage(DeployLogger::STAGE_PREPARING);
                $deployLogger->dim('Recreating home and project directories');
            }
            if ($zipPath !== null && $zipPath !== '') {
                $deployLogger->info('Importing project archive before rebuild');
            }
            if (!$reuseRunning) {
                $deployLogger->dim('Preparing container environment');
                $deployLogger->dim('Restarting container environment');
            }
            $mechanics->syncHostingForSourceRebuild($zipPath);

            $deployLogger->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestForWipeRebuild($zipPath);

            $this->startAndFinishSourceDeploy($deployLogger, $mechanics);
        } catch (DeployCancelledException $e) {
            $deployLogger->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $deployLogger->recordFailureOutput($e->getMessage());
            $deployLogger->finish(
                DeployLogger::STATUS_FAILED,
                DeployFailureExplainer::explain($e->getMessage()) ?? $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Deploy an uploaded archive into the account as it stands.
     *
     * Unlike {@see rebuildFromSource()} this does not wipe ~/project first: the
     * archive normally sits inside it. Same contract otherwise: the deploy log
     * is finished here, deployment_status is not persisted, and failures are
     * thrown as plain exceptions for the caller to map.
     */
    public function deployFromArchive(?DeployLogger $deployLogger, string $zipPath): void
    {
        $mechanics = $this->resolveMechanics();

        $deployLogger?->stage(DeployLogger::STAGE_CLONING);
        $mechanics->ingestArchive($zipPath);

        $this->startAndFinishSourceDeploy($deployLogger, $mechanics);
    }

    /**
     * Start what the source step prepared, then finish the deploy log with the
     * serving verdict. Shared by the source rebuild and the archive deploy.
     */
    private function startAndFinishSourceDeploy(?DeployLogger $deployLogger, DeployMechanics $mechanics): void
    {
        $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
        $result = $mechanics->startApplication();
        if ($result['exit_code'] !== 0) {
            $raw = $result['stderr'] ?: $result['stdout'];
            $deployLogger?->recordFailureOutput($raw);
            $message = DeployFailureExplainer::explain($raw) ?? trim($raw);
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $deployLogger?->info($hint);
            }
            $full = $hint !== null
                ? "Failed to start app: {$message} | {$hint}"
                : "Failed to start app: {$message}";
            $deployLogger?->finish(DeployLogger::STATUS_FAILED, $full);
            throw new \Exception($full);
        }

        $this->finishSourceRebuildServing($deployLogger, $mechanics);
    }

    private function finishSourceRebuildServing(?DeployLogger $logger, DeployMechanics $mechanics): void
    {
        $user = $mechanics->user();
        $warnings = array_merge(
            $mechanics->servingWarnings(),
            PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        );

        $warnings === []
            ? $logger?->finish(DeployLogger::STATUS_SUCCESS)
            : $logger?->finish(DeployLogger::STATUS_PARTIAL, implode(' | ', $warnings));
    }

    private function resolveMechanics(): DeployMechanics
    {
        if ($this->mechanics !== null) {
            return $this->mechanics;
        }
        if (!$this->project instanceof DindRuntime) {
            throw new \LogicException('Default deploy mechanics require a DinD runtime project.');
        }

        return new DindDeployMechanics($this->project);
    }

    private function startFailureMessage(string $output, ?DeployLogger $logger = null): string
    {
        $logger?->recordFailureOutput($output);

        $explanation = DeployFailureExplainer::explain($output);
        if ($explanation !== null) {
            return 'Failed to start app: ' . $explanation;
        }

        return 'Failed to start app: ' . trim($output);
    }

    private function invokeBeforeRetention(?callable $beforeRetention, ?DeployLogger $logger, string $username): void
    {
        if ($beforeRetention === null || $logger === null) {
            return;
        }
        try {
            $beforeRetention($logger);
        } catch (\Throwable $e) {
            Log::warning("Before-retention hook failed for {$username}: {$e->getMessage()}");
        }
    }

    private static function deployProblem(string $code, string $message, ?string $stage): ProblemException
    {
        return ProblemException::one('deploy', $code, $message, array_filter([
            'stage' => $stage,
            'deploy_log_offset' => 0,
        ], static fn (mixed $v): bool => $v !== null));
    }
}
