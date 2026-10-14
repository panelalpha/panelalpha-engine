<?php

namespace App\System\Project\Dind;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use App\Exceptions\DeployCancelledException;
use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Command execution inside an account's DinD container via the outer compose file.
 */
final class ShellOperations
{
    /** Env var (dind exec) and label (host docker run) a watched step is tagged with. */
    public const STEP_ENV = 'PANELALPHA_STEP';

    public const STEP_LABEL = 'panelalpha.step';

    /** Root's Docker client config in the account, in the home; see {@see wrap()}. */
    public const DOCKER_CONFIG_DIR = '.panelalpha-docker';

    /** Exit code of a shell command its timeout killed, as coreutils `timeout` reports it. */
    public const SHELL_TIMED_OUT_EXIT = 124;

    /** Seconds a timed-out shell command gets between SIGTERM and SIGKILL. */
    private const SHELL_KILL_GRACE = 5;

    /** Seconds the host waits past that grace, so the in-container `timeout` always ends the command first. */
    private const SHELL_HOST_MARGIN = 10;

    /**
     * CPU, in `docker stats` percent (100 = one core), above which a silent
     * step counts as working. An idle DinD reads 0-2%; one rustc reads ~100.
     */
    private const BUSY_CPU_PERCENT = 20.0;

    /**
     * Runs inside dind: uptime and CPU time of the whole cgroup, two seconds apart. Under sysbox
     * `docker stats` reads only init.scope, while BuildKit steps run in a sibling cgroup.
     */
    private const CGROUP_CPU_SCRIPT = 'u() { echo "$(cut -d" " -f1 /proc/uptime) $(sed -n "s/^usage_usec //p" /sys/fs/cgroup/cpu.stat)"; }; '
        . 'echo "$(u)"; sleep 2; echo "$(u)"';

    /** Runs inside dind: TERM, then KILL, every process carrying the tag ($1) in its env. */
    private const KILL_TAGGED_SCRIPT = 'for sig in TERM KILL; do '
        . 'for d in /proc/[0-9]*; do '
        . 'tr "\0" "\n" < "$d/environ" 2>/dev/null | grep -qxF "$1" && kill -s $sig "${d#/proc/}" 2>/dev/null; '
        . 'done; [ $sig = TERM ] && sleep 3; done; true';

    /** A hook's shell needs these itself; a project env var of the same name is not exported to it. */
    private const HOOK_RESERVED_ENV = ['PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'PWD', 'IFS', 'BASH_ENV', 'ENV'];

    public function __construct(
        private DindProject $project,
    ) {
    }

    public function logger(): ?DeployLogger
    {
        try {
            $logger = DeployLogger::current($this->project->username());
        } catch (\Throwable $e) {
            return null;
        }
        if ($logger === null || $logger->isRunning()) {
            return $logger;
        }
        if ($this->isWindingDownAfterCancel($logger)) {
            return $logger;
        }

        return null;
    }

    /** A cancelled deploy makes every command throw until it has finished or its process is gone. */
    private function isWindingDownAfterCancel(DeployLogger $logger): bool
    {
        return $logger->isCancelled() && !$logger->isFinished() && DeployLogger::isLockedFor($this->project->username());
    }

    /**
     * Root gets DOCKER_CONFIG in the account home, not /root: unmapped root in an account sysbox
     * started cannot enter /root, and the docker CLI then finds no compose plugin.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    public function wrap(array $cmd, bool $asUser = false): array
    {
        $args = [
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->project->composeFilePath(),
            'exec',
        ];
        if ($asUser) {
            $args[] = '-u';
            $args[] = $this->project->userModel()->getChownString();
        } elseif (($cmd[0] ?? null) !== 'su') {
            $args[] = '-e';
            $args[] = 'DOCKER_CONFIG=' . $this->dockerConfigDir();
        }
        $args[] = '-T';
        $args[] = 'dind';

        return [...$args, ...$cmd];
    }

    public function dockerConfigDir(): string
    {
        return rtrim($this->project->homeDirPath(), '/') . '/' . self::DOCKER_CONFIG_DIR;
    }

    /**
     * @param list<string> $cmd
     */
    public function execQuiet(array $cmd, array $env = [], int $timeout = 600): string
    {
        return $this->project->system()->exec($this->wrap($cmd), $env, $timeout);
    }

    /**
     * @param list<string> $cmd
     */
    public function execAsUserQuiet(array $cmd, array $env = [], int $timeout = 600): string
    {
        return $this->execQuiet($this->asAccountUser($cmd), $env, $timeout);
    }

    /**
     * @param list<string> $cmd
     * @return list<string>
     */
    private function asAccountUser(array $cmd): array
    {
        $username = $this->project->username();
        $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));

        return ['su', '-s', '/bin/bash', $username, '-c', $cmdStr];
    }

    /**
     * @param list<string> $cmd
     */
    public function exec(array $cmd, array $env = [], int $timeout = 600): string
    {
        $cmd = $this->wrap($cmd);
        $logger = $this->logger();
        if ($logger === null) {
            return $this->project->system()->exec($cmd, $env, $timeout);
        }

        $logger->throwIfCancelled();
        $process = $this->streamProcess($cmd, $env, $timeout, $logger);
        if ($logger->isCancelled()) {
            throw new DeployCancelledException('Deployment cancelled by user');
        }
        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            if (Str::contains($message, 'Error response from daemon')) {
                throw new DockerErrorException($message);
            }
            throw new \Exception($message);
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $cmd
     */
    public function execAsUser(array $cmd, array $env = [], int $timeout = 600): string
    {
        return $this->exec($this->asAccountUser($cmd), $env, $timeout);
    }

    /**
     * Run as the account user and hand each chunk of output to $onOutput as it arrives.
     * $onIdle is called after every $idleSeconds without output; throwing from it stops the run.
     *
     * @param list<string> $cmd
     * @param callable(string, string): void $onOutput
     * @param ?callable(): void $onIdle
     */
    public function followAsUser(array $cmd, int $timeout, callable $onOutput, ?callable $onIdle = null, int $idleSeconds = 2): void
    {
        $command = $this->asAccountUser($cmd);

        $lastOutput = microtime(true);
        $output = function (string $type, string $data) use ($onOutput, &$lastOutput): void {
            $lastOutput = microtime(true);
            $onOutput($type, $data);
        };
        $poll = $onIdle === null ? null : self::pollWithIdleCallback($output, $onIdle, $idleSeconds, $lastOutput);

        $this->project->system()->runProcessWithCallbacks(
            $this->wrap($command),
            [],
            $timeout,
            $poll,
            $output,
        );
    }

    /**
     * Polls instead of Process::wait(), which returns only when output arrives while a quiet service
     * may print nothing for minutes; so it also reads the output itself and hands it to $output.
     *
     * @param callable(string, string): void $output
     * @param callable(): void $onIdle
     * @return \Closure(Process): void
     */
    private static function pollWithIdleCallback(callable $output, callable $onIdle, int $idleSeconds, float &$lastOutput): \Closure
    {
        return function (Process $process) use ($output, $onIdle, $idleSeconds, &$lastOutput): void {
            do {
                $running = $process->isRunning();
                $process->checkTimeout();
                self::forwardIncrementalOutput($process, $output);
                if ($running && microtime(true) - $lastOutput >= $idleSeconds) {
                    $lastOutput = microtime(true);
                    $onIdle();
                }
                if ($running) {
                    usleep(100_000);
                }
            } while ($running);
        };
    }

    /** @param callable(string, string): void $output */
    private static function forwardIncrementalOutput(Process $process, callable $output): void
    {
        $out = $process->getIncrementalOutput();
        $err = $process->getIncrementalErrorOutput();
        $process->clearOutput()->clearErrorOutput();
        if ($out !== '') {
            $output(Process::OUT, $out);
        }
        if ($err !== '') {
            $output(Process::ERR, $err);
        }
    }

    /**
     * execAsUser() with the project's env vars exported, so a recipe hook can check them. The values
     * go through a 0600 file in the home, never argv or the deploy log.
     *
     * @param list<string> $cmd
     */
    public function execAsUserWithProjectEnv(array $cmd, int $timeout = 600): string
    {
        $user = $this->project->userModel();
        $lines = self::hookEnvLines($user->getEnvVars());
        if ($lines === []) {
            return $this->execAsUser($cmd, [], $timeout);
        }

        $path = rtrim($this->project->homeDirPath(), '/') . '/.panelalpha-hook-env-' . bin2hex(random_bytes(8));
        $this->project->system()->filesystem()->filePutContents(
            $path,
            implode("\n", $lines) . "\n",
            $user->getChownString() ?: $this->project->username(),
            '600',
        );
        try {
            return $this->execAsUser(
                ['bash', '-c', 'set -a && . "$1" && set +a && shift && exec "$@"', 'hook-env', $path, ...$cmd],
                [],
                $timeout,
            );
        } finally {
            $this->removeHookEnvFile($path);
        }
    }

    /**
     * Shell assignments for every env var a hook's shell does not reserve for itself.
     *
     * @param array<string, string> $envVars
     * @return list<string>
     */
    private static function hookEnvLines(array $envVars): array
    {
        $lines = [];
        foreach ($envVars as $key => $value) {
            if (!in_array($key, self::HOOK_RESERVED_ENV, true)) {
                $lines[] = $key . '=' . escapeshellarg((string) $value);
            }
        }

        return $lines;
    }

    /** Best effort: the hook's own outcome matters more, and the file is the account's own, 0600. */
    private function removeHookEnvFile(string $path): void
    {
        try {
            $this->execQuiet(['rm', '-f', $path]);
        } catch (\Throwable) {
        }
    }

    /**
     * One command line as the account user, reported rather than thrown: a command its
     * timeout killed is exit code 124 with whatever it printed before.
     *
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runShellCommand(string $command, ?string $cwd = null, int $timeout = 300): array
    {
        try {
            $process = $this->runShellAsUser($command, $cwd, $timeout);
        } catch (ProcessTimedOutException $e) {
            return self::shellResult($e->getProcess(), self::SHELL_TIMED_OUT_EXIT);
        }

        return self::shellResult($process, $process->getExitCode() ?? self::SHELL_TIMED_OUT_EXIT);
    }

    /**
     * The timeout runs inside the account: killing the exec on the host leaves the
     * command running there, so the host only waits a little longer as a backstop.
     */
    public function runShellAsUser(string $command, ?string $cwd = null, int $timeout = 300): Process
    {
        $username = $this->project->username();

        return $this->project->system()->runProcess(
            $this->wrap(['su', '-s', '/bin/bash', '-l', $username, '-c', self::timedShellCommand(self::composeShellCommand($command, $cwd), $timeout)]),
            [],
            $timeout + self::SHELL_KILL_GRACE + self::SHELL_HOST_MARGIN
        );
    }

    /**
     * `timeout` signals its whole process group, so background jobs and pipelines
     * end with the command instead of outliving the request.
     */
    public static function timedShellCommand(string $command, int $timeout): string
    {
        return 'exec timeout -k ' . self::SHELL_KILL_GRACE . ' ' . $timeout . ' bash -c ' . escapeshellarg($command);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private static function shellResult(Process $process, int $exitCode): array
    {
        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $exitCode,
        ];
    }

    public static function composeShellCommand(string $command, ?string $cwd = null): string
    {
        if ($cwd === null || $cwd === '') {
            return $command;
        }

        return 'cd ' . escapeshellarg($cwd) . ' && ' . $command;
    }

    /**
     * @param list<string> $cmd
     */
    public function runProcess(array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->project->system()->runProcess($this->wrap($cmd), $env, $timeout);
    }

    /**
     * @param list<string> $cmd
     */
    public function runProcessAsUser(array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->project->system()->runProcess($this->wrap($cmd, true), $env, $timeout);
    }

    /**
     * Run a deploy step into the log under a watchdog. A stalled, disk-limited or cancelled step is
     * also stopped inside the account by its tag, since killing the exec on the host does not reach it.
     *
     * @param list<string> $cmd
     */
    public function streamProcess(array $cmd, array $env, int $timeout, DeployLogger $logger): Process
    {
        $idle = (int) config('deploy.step_idle_timeout', 900);
        $diskFull = $this->diskLimitProbe();
        $tag = $idle > 0 || $diskFull !== null ? bin2hex(random_bytes(6)) : null;
        $watchdog = null;
        if ($tag !== null) {
            $label = self::stepLabel($cmd);
            $cmd = $this->tagStep($cmd, $tag);
            $watchdog = $this->stepWatchdog($cmd, $tag, $idle, $label, $diskFull);
        }

        try {
            $process = $this->runLogged($cmd, $env, $timeout, $logger, $watchdog);
        } catch (BuildStalledException $e) {
            $this->failStep($logger, $e, $cmd, (string) $tag);
            throw $e;
        } catch (DiskLimitException $e) {
            $this->failStep($logger, $e, $cmd, (string) $tag);
            $this->reclaimStorageAfterDiskLimit($logger);
            throw $e;
        } finally {
            $logger->setPid(null);
            $logger->flushBuffers();
        }

        if ($tag !== null && $logger->isCancelled()) {
            $this->stopTaggedStep($cmd, $tag);
        }

        return $process;
    }

    /**
     * @param list<string> $tagged
     * @param ?\Closure(): ?string $diskFull
     */
    private function stepWatchdog(array $tagged, string $tag, int $idle, string $label, ?\Closure $diskFull): StepWatchdog
    {
        return new StepWatchdog(max(0, $idle), $label, fn (): bool => $this->stepIsBusy($tagged, $tag), $diskFull);
    }

    /**
     * @param list<string> $cmd
     */
    private function runLogged(array $cmd, array $env, int $timeout, DeployLogger $logger, ?StepWatchdog $watchdog): Process
    {
        return $this->project->system()->runProcessWithCallbacks(
            $cmd,
            $env,
            $timeout,
            function (Process $process) use ($logger) {
                $logger->setPid($process->getPid());
            },
            function (string $type, string $data) use ($logger) {
                $logger->writeProcessBuffer($type, $data);
            },
            $watchdog
        );
    }

    /**
     * @param list<string> $cmd
     */
    private function failStep(DeployLogger $logger, \Throwable $e, array $cmd, string $tag): void
    {
        $logger->flushBuffers();
        $logger->error($e->getMessage());
        $this->stopTaggedStep($cmd, $tag);
    }

    /** Give back what the step took, a half-done pull included. */
    private function reclaimStorageAfterDiskLimit(DeployLogger $logger): void
    {
        try {
            $this->project->innerDocker()->reclaimStorageAfterDiskLimit();
        } catch (\Throwable $reclaim) {
            $logger->warn('Could not reclaim the account\'s Docker storage: ' . $reclaim->getMessage());
        }
    }

    /** @return ?\Closure(): ?string */
    private function diskLimitProbe(): ?\Closure
    {
        try {
            $limit = StepDiskLimit::for($this->project);
        } catch (\Throwable) {
            return null;
        }

        return $limit === null ? null : static fn (): ?string => $limit->reason();
    }

    /**
     * @param list<string> $cmd
     * @return list<string>
     */
    public function tagStep(array $cmd, string $tag): array
    {
        if ($this->isDindExec($cmd)) {
            array_splice($cmd, 6, 0, ['-e', self::STEP_ENV . '=' . $tag]);
        } elseif (self::isHostDockerRun($cmd)) {
            array_splice($cmd, 3, 0, ['--label', self::STEP_LABEL . '=' . $tag]);
        }

        return $cmd;
    }

    /**
     * @param list<string> $cmd
     */
    private function stopTaggedStep(array $cmd, string $tag): void
    {
        $system = $this->project->system();
        try {
            if ($this->isDindExec($cmd)) {
                $system->runProcess($this->wrap(['sh', '-c', self::KILL_TAGGED_SCRIPT, 'sh', self::STEP_ENV . '=' . $tag]), [], 30);
            } elseif (self::isHostDockerRun($cmd)) {
                $ids = self::taggedHostContainerIds($system, $tag);
                if ($ids !== []) {
                    $system->runProcess(['sudo', 'docker', 'rm', '-f', ...$ids], [], 60);
                }
            }
        } catch (\Throwable $e) {
            $this->logger()?->warn('Could not stop what the step left running: ' . $e->getMessage());
        }
    }

    /**
     * Is a silent step still working, by its container's CPU: the DinD cgroup for a build inside the
     * account (BuildKit is not a child of the exec), `docker stats` of the tagged container for a host build.
     *
     * @param list<string> $cmd
     */
    public function stepIsBusy(array $cmd, string $tag): bool
    {
        $system = $this->project->system();
        $stats = ['sudo', 'docker', 'stats', '--no-stream', '--format', '{{.CPUPerc}}'];
        if ($this->isDindExec($cmd)) {
            $percent = $this->dindCgroupCpuPercent($system);
            if ($percent !== null) {
                return $percent >= self::BUSY_CPU_PERCENT;
            }
            $argv = ['sudo', 'docker', 'compose', '-f', $this->project->composeFilePath(), ...array_slice($stats, 2), 'dind'];
        } elseif (self::isHostDockerRun($cmd)) {
            $ids = self::taggedHostContainerIds($system, $tag);
            if ($ids === []) {
                return false;
            }
            $argv = [...$stats, ...$ids];
        } else {
            return false;
        }

        return self::cpuPercent($system->exec($argv, [], 30)) >= self::BUSY_CPU_PERCENT;
    }

    private function dindCgroupCpuPercent(System $system): ?float
    {
        try {
            return self::cgroupCpuPercent($system->exec($this->wrap(['sh', '-c', self::CGROUP_CPU_SCRIPT]), [], 30));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function taggedHostContainerIds(System $system, string $tag): array
    {
        $ids = preg_split('/\s+/', trim($system->exec(
            ['sudo', 'docker', 'ps', '-q', '--filter', 'label=' . self::STEP_LABEL . '=' . $tag],
            [],
            30
        ))) ?: [];

        return array_values(array_filter($ids));
    }

    /**
     * CPU percent (100 = one core) from two `<uptime> <usage_usec>` lines of
     * {@see CGROUP_CPU_SCRIPT}; null when they cannot be read.
     */
    public static function cgroupCpuPercent(string $samples): ?float
    {
        if (preg_match_all('/^\s*([0-9]+(?:\.[0-9]+)?)\s+([0-9]+)\s*$/m', $samples, $m) !== 2) {
            return null;
        }
        $elapsed = (float) $m[1][1] - (float) $m[1][0];
        $used = (int) $m[2][1] - (int) $m[2][0];
        if ($elapsed <= 0 || $used < 0) {
            return null;
        }

        return $used / 1_000_000 / $elapsed * 100;
    }

    /** Sum of `docker stats` CPUPerc lines, e.g. "102.15%". */
    public static function cpuPercent(string $stats): float
    {
        preg_match_all('/^\s*([0-9]+(?:\.[0-9]+)?)%\s*$/m', $stats, $m);

        return array_sum(array_map('floatval', $m[1]));
    }

    /** @param list<string> $cmd */
    private function isDindExec(array $cmd): bool
    {
        return array_slice($cmd, 0, 6) === ['sudo', 'docker', 'compose', '-f', $this->project->composeFilePath(), 'exec'];
    }

    /** @param list<string> $cmd */
    private static function isHostDockerRun(array $cmd): bool
    {
        return array_slice($cmd, 0, 3) === ['sudo', 'docker', 'run'];
    }

    /**
     * @param list<string> $cmd
     */
    public static function stepLabel(array $cmd): string
    {
        $dind = array_search('dind', $cmd, true);
        if ($cmd[0] === 'sudo' && ($cmd[2] ?? null) === 'compose' && $dind !== false) {
            $cmd = array_slice($cmd, $dind + 1);
        }
        if (($cmd[0] ?? null) === 'su' && ($c = array_search('-c', $cmd, true)) !== false) {
            return (string) ($cmd[$c + 1] ?? '');
        }
        if (self::isHostDockerRun($cmd)) {
            return 'host build: ' . (string) end($cmd);
        }

        return implode(' ', $cmd);
    }
}
