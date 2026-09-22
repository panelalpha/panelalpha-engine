<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Project\Dind as DindProject;
use Symfony\Component\Process\Process;

/**
 * Inner `docker compose up` for clone/push and other App\System callers.
 */
final class AppLauncher
{
    private const COMPOSE_TIMEOUT_SECONDS = 3600;

    private const FAILURE_LOG_LINES = 60;

    private const FAILURE_LOG_TIMEOUT_SECONDS = 30;

    private const PS_TIMEOUT_SECONDS = 30;

    public function __construct(
        private DindProject $project,
    ) {
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     * @throws \Exception
     */
    public function start(): array
    {
        $model = $this->project->userModel();
        $strategy = $model->getDeployStrategy();
        $runtime = $model->getDeployRuntime();
        $skipBuild = DeployCompose::skipBuild($strategy, $runtime);

        $this->preloadImages($strategy, $runtime, $skipBuild, $model->getDeployImage());

        $command = $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans']);
        if (DeployCompose::forceRecreate($strategy, $runtime)) {
            $command[] = '--force-recreate';
        }
        if ($skipBuild) {
            $command[] = '--no-build';
        } else {
            $command[] = '--build';
        }

        $process = $this->run($command);
        $process = $this->retryOnceIfDiskFull($process, $command);

        if ($process->getExitCode() !== 0) {
            $this->recordContainerOutput();
        }

        if ($process->getExitCode() === 0) {
            $failure = $this->gateFailure();
            if ($failure !== null) {
                $this->recordContainerOutput();

                return [
                    'stdout' => $process->getOutput(),
                    'stderr' => $failure,
                    'exit_code' => 1,
                ];
            }
            $this->project->alignAppPort();
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * A service that ran, failed, and left `up -d` exiting 0.
     *
     * Without `--wait`, `up -d` returns once containers are created and
     * started and does not care what exit code a one-shot service produces.
     * So a gate service -- one whose whole job is to refuse the deploy if a
     * post-condition does not hold -- can run, print its refusal, exit 1, and
     * the deploy is still reported successful.
     *
     * Measured on Manticore (supported-apps#297) with authentication
     * deliberately switched off: `ready` exited 1, `up -d` exited 0, the
     * rebuild endpoint returned success, and the account was published with
     * an unauthenticated writable search engine on it. The thing the gate
     * existed to prevent is exactly what shipped. Baikal (#274), Mattermost
     * (#47) and Dolibarr (#307) all rely on a gate to close a
     * first-visitor-wins installer before the port opens.
     *
     * Reading exit codes after the fact rather than passing `--wait`: `--wait`
     * also waits on health checks, so any service with a slow or wrong one
     * would start delaying or failing deploys that pass today. That is a
     * change worth measuring against real projects first, and it is not
     * needed to close this -- the exit codes are already there to read.
     */
    private function gateFailure(): ?string
    {
        $shell = $this->project->shell();
        try {
            $output = $shell->execAsUserQuiet(
                $this->project->userAppComposeCommand(['ps', '--all', '--format', 'json']),
                [],
                self::PS_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            // Never turn "could not ask" into "failed": that would be the same
            // defect pointed the other way.
            return null;
        }

        $failed = self::failedServices($output);
        if ($failed === []) {
            return null;
        }

        $logger = $shell->logger();
        foreach ($failed as $service => $code) {
            $logger?->info("Service {$service} exited with code {$code}; the deploy is not successful");
        }

        $described = [];
        foreach ($failed as $service => $code) {
            $described[] = "{$service} (exit {$code})";
        }

        return 'A service exited non-zero after the stack came up: ' . implode(', ', $described);
    }

    /**
     * Services that exited non-zero, name => exit code.
     *
     * Its own function, over `docker compose ps --format json`, so the rule
     * can be tested against real compose output with no daemon. Compose
     * writes either one JSON array or one object per line depending on the
     * version, and both are read here.
     *
     * Only `exited`: a service that is running, restarting or created has not
     * reported anything yet, and a one-shot that exited 0 did its job.
     *
     * @return array<string, int>
     */
    public static function failedServices(string $psOutput): array
    {
        $rows = [];
        $whole = json_decode(trim($psOutput), true);
        if (is_array($whole) && array_is_list($whole)) {
            $rows = $whole;
        } else {
            foreach (preg_split('/\R/', $psOutput) ?: [] as $line) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        $failed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $state = strtolower((string) ($row['State'] ?? ''));
            $code = $row['ExitCode'] ?? null;
            $name = (string) ($row['Service'] ?? $row['Name'] ?? '');
            if ($name !== '' && $state === 'exited' && is_int($code) && $code !== 0) {
                $failed[$name] = $code;
            }
        }

        return $failed;
    }

    /**
     * @param string|null $strategy
     * @param string|null $runtime
     * @param bool $skipBuild
     * @param string|null $resolvedImage
     * @throws \Exception
     */
    private function preloadImages(
        ?string $strategy,
        ?string $runtime,
        bool $skipBuild,
        ?string $resolvedImage = null
    ): void {
        $innerDocker = $this->project->innerDocker();

        if ($resolvedImage !== null && $resolvedImage !== '') {
            $innerDocker->ensureImage($resolvedImage);
        }

        if ($runtime === PlatformManifest::RUNTIME_NGINX) {
            $this->project->hostCompile()->run();
            $innerDocker->ensureImage(Images::NGINX_IMAGE);
        } elseif (StandaloneNodeServe::isStandaloneStrategy($strategy) || HostRunProject::isStrategy($strategy)) {
            $this->project->hostCompile()->run();
            if (HostRunProject::isNode($strategy) || StandaloneNodeServe::isStandaloneStrategy($strategy)) {
                $innerDocker->ensureImage(Images::nodeImage($this->project->userAppDirPath()));
            }
        } elseif (!$skipBuild) {
            $innerDocker->preloadFrameworkBaseImages($strategy, $runtime);
        }
        $innerDocker->preloadComposeImages($this->project->userAppComposeFileToRun());
        if (!DeployCompose::skipReclaimBeforeBuild($strategy, $runtime)) {
            $innerDocker->reclaimStorageIfNeeded();
        }
    }

    /**
     * @param list<string> $command
     */
    private function retryOnceIfDiskFull(Process $process, array $command): Process
    {
        $innerDocker = $this->project->innerDocker();
        $message = $process->getErrorOutput() . "\n" . $process->getOutput();
        if (
            $process->getExitCode() === 0
            || !DindBuildStorage::isDiskFullError($message)
            || !$innerDocker->canAggressivelyReclaim()
        ) {
            return $process;
        }

        $this->project->shell()->logger()?->info(
            'Build hit ENOSPC; reclaiming inner Docker cache and retrying once'
        );
        Telemetry::signal(
            $this->project->userModel()->username,
            'enospc-retry',
            'Build hit ENOSPC; reclaimed the inner Docker cache and retried once'
        );
        $innerDocker->reclaimStorage(true);

        return $this->run($command);
    }

    private function recordContainerOutput(): void
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        if ($logger === null) {
            return;
        }

        try {
            $output = $shell->execAsUserQuiet(
                $this->project->userAppComposeCommand([
                    'logs',
                    '--tail=' . self::FAILURE_LOG_LINES,
                    '--no-color',
                    '--timestamps',
                ]),
                [],
                self::FAILURE_LOG_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return;
        }

        $output = trim($output);
        if ($output === '') {
            return;
        }

        $logger->info('Container output at the point of failure:');
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) !== '') {
                $logger->info($line);
            }
        }
    }

    /**
     * @param list<string> $command
     * @throws DeployCancelledException
     */
    private function run(array $command): Process
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        if ($logger === null) {
            return $shell->runProcess($command, [], self::COMPOSE_TIMEOUT_SECONDS);
        }

        $logger->throwIfCancelled();
        $logger->info('Starting application (docker compose up -d)');
        $process = $shell->streamProcess(
            $shell->wrap($command),
            [],
            self::COMPOSE_TIMEOUT_SECONDS,
            $logger
        );
        $logger->throwIfCancelled();

        return $process;
    }
}
