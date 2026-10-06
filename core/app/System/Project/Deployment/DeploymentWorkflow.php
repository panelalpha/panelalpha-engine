<?php

namespace App\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Domains\PublicUrl;
use App\System\Project\Dind as DindRuntime;
use App\System\Project\Dind\AppLauncher;
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
    ) {
    }

    /**
     * A workflow over mechanics that are not the DinD default.
     *
     * $explainNoiseOnlyOutput false keeps the template path's own reporting: a
     * start failure whose output is all noise is reported as it stands, where
     * DinD still asks the explainer about the whole of it.
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
        return $this->disposition ??= new RetainProject();
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
                $deployLogger?->info(DeployLogger::WAITING_FOR_FILES);

                return;
            }

            $deployLogger?->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestApplicationSource();
            $deployLogger?->stage(DeployLogger::STAGE_RUNNING);
            $mechanics->publishDomain($domain);
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->invokeBeforeRetention($beforeRetention, $deployLogger, $user->username, cancelled: true);
            $this->disposition()->afterCancelled($user, $e->getMessage());
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
            $this->disposition()->afterFailure($user, $message);
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
                        // A first deploy: the volumes hold only what this failed start wrote.
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
            $this->disposition()->afterFailure($user, $summary);
            $match = DeployFailureExplainer::match(implode("\n", $failureOutputs));
            throw self::deployProblem($match['rule'] ?? 'app_did_not_start', $summary, $stage);
        }

        $serving = $mechanics->servingWarnings();
        $warnings = array_merge($warnings, $serving);
        $warnings = array_merge($warnings, $mechanics->publicUrlWarnings($domain));
        self::logDomainNotices($deployLogger, $mechanics->user());

        if ($warnings !== []) {
            // Only an app that is not serving can have been broken by its variables,
            // not a public name or certificate warning.
            $hint = $serving !== [] ? $mechanics->customEnvFailureHint() : null;
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

        $succeeded = false;
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
                if (!empty($result[AppLauncher::RELEASE_FAILED])) {
                    // Nothing new was started: tearing down now would only stop the version still serving.
                    $deployLogger->info(self::releaseFailedLine($result));
                } elseif (!empty($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT])) {
                    // The new version never replaced the old one: tearing down would stop what still serves.
                    $deployLogger->info('The previous version is still serving; nothing was torn down');
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
                $deployLogger->finish(DeployLogger::STATUS_FAILED, $message);
                $this->disposition()->afterFailure($user, $message);
                $match = DeployFailureExplainer::match($output);
                throw self::deployProblem($match['rule'] ?? 'app_did_not_start', $message, $deployLogger->currentStage());
            }

            $serving = $mechanics->servingWarnings();
            $warnings = array_merge(
                $serving,
                $mechanics->publicUrlWarnings($domain),
            );
            self::logDomainNotices($deployLogger, $user);
            if ($warnings !== []) {
                $hint = $serving !== [] ? $mechanics->customEnvFailureHint() : null;
                if ($hint !== null) {
                    $warnings[] = $hint;
                }
                $mechanics->persistPartialSuccess($warnings);
                $deployLogger->finish(DeployLogger::STATUS_PARTIAL, implode(' | ', $warnings));
            } else {
                $mechanics->persistSuccess();
                $deployLogger->finish(DeployLogger::STATUS_SUCCESS);
            }
            $succeeded = true;
        } catch (DeployCancelledException $e) {
            $stage = $deployLogger->currentStage();
            $deployLogger->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            $this->disposition()->afterCancelled($user, $e->getMessage());
            throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
        } catch (ProblemException $e) {
            throw $e;
        } catch (\Exception $e) {
            $deployLogger->recordFailureOutput($e->getMessage());
            $match = DeployFailureExplainer::match($e->getMessage());
            $message = $match['message'] ?? $e->getMessage();
            $deployLogger->finish(DeployLogger::STATUS_FAILED, $message);
            $this->disposition()->afterFailure($user, $message);
            throw self::deployProblem($match['rule'] ?? 'deploy_failed', $message, $deployLogger->currentStage());
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
    }

    /**
     * Wipe-and-redeploy from git or zip (HTTP/artisan rebuild path).
     *
     * Finishes the deploy log and records a failure on the project; success is
     * persisted by callers such as UserController::recordRebuildSucceeded.
     * A start failure is thrown as a ProblemException carrying the explainer's
     * rule; anything else as a plain \Exception.
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

        $succeeded = false;
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
            $succeeded = true;
        } catch (DeployCancelledException $e) {
            $deployLogger->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
            FailureRetention::retainAfterDeployCancelled($user, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $deployLogger->recordFailureOutput($e->getMessage());
            $message = DeployFailureExplainer::explain($e->getMessage()) ?? FailureOutput::withoutNoise($e->getMessage());
            $deployLogger->finish(DeployLogger::STATUS_FAILED, $message);
            FailureRetention::retainAfterDeployFailure($user, $message);
            throw $e;
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
    }

    /**
     * Deploy an uploaded archive into the account as it stands.
     *
     * Unlike {@see rebuildFromSource()} this does not wipe ~/project first: the
     * archive normally sits inside it. Same contract otherwise: the deploy log
     * is finished here, a failure is recorded on the project, and a start failure
     * is a ProblemException; anything else is a plain exception for the caller to map.
     */
    public function deployFromArchive(?DeployLogger $deployLogger, string $zipPath): void
    {
        $mechanics = $this->resolveMechanics();

        $succeeded = false;
        try {
            $deployLogger?->stage(DeployLogger::STAGE_CLONING);
            $mechanics->ingestArchive($zipPath);

            $this->startAndFinishSourceDeploy($deployLogger, $mechanics);
            $succeeded = true;
        } catch (\InvalidArgumentException $e) {
            // A refused archive (the caller answers 422 on zip_path): nothing was replaced.
            throw $e;
        } catch (DeployCancelledException $e) {
            FailureRetention::retainAfterDeployCancelled($mechanics->user(), $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $message = DeployFailureExplainer::explain($e->getMessage()) ?? FailureOutput::withoutNoise($e->getMessage());
            FailureRetention::retainAfterDeployFailure($mechanics->user(), $message);
            throw $e;
        } finally {
            $mechanics->settleRedeploy($succeeded);
        }
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
            if (!empty($result[AppLauncher::RELEASE_FAILED])) {
                $deployLogger?->info(self::releaseFailedLine($result));
            } elseif (!empty($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT])) {
                // Said as a checkout redeploy says it: the failed version never replaced the running one.
                $deployLogger?->info('The previous version is still serving; nothing was torn down');
            }
            $raw = $result['stderr'] ?: $result['stdout'];
            $deployLogger?->recordFailureOutput($raw);
            $message = $this->failureSentence($raw);
            $hint = $mechanics->customEnvFailureHint();
            if ($hint !== null) {
                $deployLogger?->info($hint);
            }
            $full = self::startFailure($message) . ($hint !== null ? " | {$hint}" : '');
            // The rule is matched here, on the output, as telemetry does: the
            // sentence thrown on no longer carries anything a rule can match.
            $region = trim(FailureOutput::select($raw));
            $match = ($region !== '' ? DeployFailureExplainer::match($region) : null) ?? DeployFailureExplainer::match($raw);
            $stage = $deployLogger?->currentStage();
            $deployLogger?->finish(DeployLogger::STATUS_FAILED, $full);
            throw self::deployProblem($match['rule'] ?? 'app_did_not_start', $full, $stage);
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
        $warnings = array_merge(
            $mechanics->servingWarnings(),
            PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        );
        self::logDomainNotices($logger, $user);

        $warnings === []
            ? $logger?->finish(DeployLogger::STATUS_SUCCESS)
            : $logger?->finish(DeployLogger::STATUS_PARTIAL, implode(' | ', $warnings));
    }

    /** A fallback domain that still works: said in the log, not a warning (#79). */
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
        if (!$this->project instanceof DindRuntime) {
            throw new \LogicException('Default deploy mechanics require a DinD runtime project.');
        }

        return new DindDeployMechanics($this->project);
    }

    /**
     * A build writes a layer banner per step and megabytes of log, and the
     * explainer matches anywhere in it -- so narrow to the failing region
     * first, or the sentence names the banner printed before anything broke.
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
     * The explainer's sentence, else the failing region, as the first deploy
     * reports it ({@see \App\Http\Controllers\UserController}). The whole
     * output of a compose up is mostly pull progress.
     */
    private function failureSentence(string $output): string
    {
        $region = trim(FailureOutput::select($output));
        if ($region === '' && !$this->explainNoiseOnlyOutput) {
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
        if ($cancelled && !$this->disposition()->hookRunsOnCancel()) {
            return;
        }
        try {
            $beforeRetention($logger);
        } catch (\Throwable $e) {
            Log::warning('Before-' . $this->disposition()->hookName() . " hook failed for {$username}: {$e->getMessage()}");
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
