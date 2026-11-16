<?php

namespace App\System\Project\Dind\Inner;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Dind\DindBuildStorage;
use App\System\Project\Dind\InnerDocker;
use Symfony\Component\Process\Process;

/**
 * Host-side work for an account's deploy: cancellable when this deploy waits for it, detached when only the
 * next one benefits; every path stops at the first ENOSPC with {@see DeployFailureExplainer}'s sentence.
 */
class HostCommands
{
    private InnerDocker $inner;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Best effort, since a failure costs only a retry by the next deploy; one that did not start is a warning.
     *
     * @param  list<string>  $argv
     */
    public function inBackground(array $argv): void
    {
        try {
            $process = $this->inner->dind()->system()->runProcess($argv, [], 5);
            $failure = $process->isSuccessful() ? null : self::failureOf($process);
        } catch (\Exception $e) {
            $failure = $e->getMessage();
        }
        if ($failure !== null) {
            $this->logWarn("Background host work did not start; the next deploy tries again: {$failure}");
        }
    }

    /**
     * Host-side docker/build steps that must honour deploy cancellation via PID.
     *
     * @param  list<string>  $argv
     */
    public function cancellable(array $argv, int $timeout): void
    {
        $shell = $this->inner->dind()->shell();
        $logger = $shell->logger();

        if ($logger === null) {
            $this->inner->dind()->system()->exec($argv, [], $timeout);

            return;
        }

        $logger->throwIfCancelled();
        $process = $shell->streamProcess($argv, [], $timeout, $logger);
        $logger->throwIfCancelled();
        if (! $process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new \Exception($message !== '' ? $message : 'Host Docker command failed');
        }
    }

    /**
     * Stop at the first ENOSPC with the sentence DeployFailureExplainer produces, rather than carry on and
     * reach the customer as a generic error several steps later.
     */
    public function failDeployIfDiskFull(string $output): void
    {
        if (! DindBuildStorage::isDiskFullError($output)) {
            return;
        }

        throw new \RuntimeException(
            DeployFailureExplainer::explain($output) ?? trim($output)
        );
    }

    public function logInfo(string $message): void
    {
        try {
            $this->inner->dind()->shell()->logger()?->info($message);
        } catch (\Exception $e) {
            $this->failDeployIfDiskFull($e->getMessage());
            throw $e;
        }
    }

    public function logWarn(string $message): void
    {
        try {
            $this->inner->dind()->shell()->logger()?->warn($message);
        } catch (\Exception $e) {
            $this->failDeployIfDiskFull($e->getMessage());
            throw $e;
        }
    }

    public function logDim(string $message): void
    {
        try {
            $this->inner->dind()->shell()->logger()?->dim($message);
        } catch (\Exception $e) {
            $this->failDeployIfDiskFull($e->getMessage());
            throw $e;
        }
    }

    private static function failureOf(Process $process): string
    {
        $output = trim($process->getErrorOutput() ?: $process->getOutput());

        return $output !== '' ? $output : 'exit code '.$process->getExitCode();
    }
}
