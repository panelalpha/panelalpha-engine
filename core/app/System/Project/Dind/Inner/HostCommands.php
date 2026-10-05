<?php

namespace App\System\Project\Dind\Inner;

use App\System\Project\Dind\InnerDocker;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Dind\DindBuildStorage;

/**
 * Running host-side work on behalf of an account's deploy, and saying so in
 * its log.
 *
 * Two ways to run something, and the choice is about who is waiting. A
 * cancellable command is one this deploy needs finished, so it streams into
 * the deploy log and honours a cancel; a background one is work the *next*
 * deploy will benefit from, detached so nobody waits and failure costs
 * nothing but a retry later.
 *
 * Every path through here checks for ENOSPC. Image seeding used to log "host
 * import failed" and carry on, so a full disk reached the customer as a
 * generic error several steps later; stopping at the first one turns it into
 * the sentence {@see DeployFailureExplainer} produces.
 */
class HostCommands
{
    private InnerDocker $inner;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Detach a host command so the deploy that asked for it does not wait.
     * Best-effort by design: if it fails, the only cost is that the next
     * deploy tries again.
     */
    public function inBackground(string $script): void
    {
        try {
            $this->inner->dind()->system()->runProcess(
                ['sudo', 'sh', '-c', 'nohup sh -c ' . escapeshellarg($script) . ' >/dev/null 2>&1 &'],
                [],
                5
            );
        } catch (\Exception $e) {
            // The stock-image path already covers this deploy.
        }
    }

    /**
     * Host-side docker/build steps that must honour deploy cancellation via PID.
     *
     * @param list<string>|string $cmd
     */
    public function cancellable(array|string $cmd, int $timeout): void
    {
        $shell = $this->inner->dind()->shell();
        $logger = $shell->logger();
        $argv = is_array($cmd) ? $cmd : ['sudo', 'sh', '-c', $cmd];

        if ($logger === null) {
            $this->inner->dind()->system()->exec($argv, [], $timeout);

            return;
        }

        $logger->throwIfCancelled();
        $process = $shell->streamProcess($argv, [], $timeout, $logger);
        $logger->throwIfCancelled();
        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new \Exception($message !== '' ? $message : 'Host Docker command failed');
        }
    }

    /**
     * Image seeding used to log "host import failed" and carry on, so a full
     * disk reached the customer as a generic error several steps later. Stop
     * at the first ENOSPC with the sentence DeployFailureExplainer produces.
     */
    public function failDeployIfDiskFull(string $output): void
    {
        if (!DindBuildStorage::isDiskFullError($output)) {
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
}
