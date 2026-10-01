<?php

namespace App\System\Project\Dind;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DeployCancelledException;
use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Command execution inside an account's DinD container via the outer compose file.
 */
final class ShellOperations
{
    /** Env var (dind exec) and label (host docker run) a watched step is tagged with. */
    public const STEP_ENV = 'PANELALPHA_STEP';

    public const STEP_LABEL = 'panelalpha.step';

    /**
     * CPU, in `docker stats` percent (100 = one core), above which a silent
     * step counts as working. An idle DinD reads 0-2%; one rustc reads ~100.
     */
    private const BUSY_CPU_PERCENT = 20.0;

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
        if ($logger === null) {
            return null;
        }
        if (!$logger->isRunning() && !$logger->isCancelled()) {
            return null;
        }

        return $logger;
    }

    /**
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
        }
        $args[] = '-T';
        $args[] = 'dind';

        return [...$args, ...$cmd];
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
        $username = $this->project->username();
        $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));

        return $this->execQuiet(['su', '-s', '/bin/bash', $username, '-c', $cmdStr], $env, $timeout);
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
        $username = $this->project->username();
        $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));

        return $this->exec(['su', '-s', '/bin/bash', $username, '-c', $cmdStr], $env, $timeout);
    }

    /**
     * execAsUser() with the project's env vars exported, so a recipe hook can
     * check them (a precheck refusing a deploy for a missing token). The values
     * go through a 0600 file in the home, never argv or the deploy log.
     *
     * @param list<string> $cmd
     */
    public function execAsUserWithProjectEnv(array $cmd, int $timeout = 600): string
    {
        $user = $this->project->userModel();
        $lines = [];
        foreach ($user->getEnvVars() as $key => $value) {
            if (!in_array($key, self::HOOK_RESERVED_ENV, true)) {
                $lines[] = $key . '=' . escapeshellarg((string) $value);
            }
        }
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
            try {
                $this->execQuiet(['rm', '-f', $path]);
            } catch (\Throwable) {
                // The hook's own outcome matters more; the file is the account's, 0600.
            }
        }
    }

    public function runShellAsUser(string $command, ?string $cwd = null, int $timeout = 300): Process
    {
        $username = $this->project->username();

        return $this->project->system()->runProcess(
            $this->wrap(['su', '-s', '/bin/bash', '-l', $username, '-c', self::composeShellCommand($command, $cwd)]),
            [],
            $timeout
        );
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
     * @param list<string> $cmd
     */
    public function streamProcess(array $cmd, array $env, int $timeout, DeployLogger $logger): Process
    {
        $idle = (int) config('deploy.step_idle_timeout', 900);
        $tag = $idle > 0 ? bin2hex(random_bytes(6)) : null;
        $watchdog = null;
        if ($tag !== null) {
            $label = self::stepLabel($cmd);
            $cmd = $this->tagStep($cmd, $tag);
            $tagged = $cmd;
            $watchdog = new StepWatchdog($idle, $label, fn (): bool => $this->stepIsBusy($tagged, $tag));
        }

        try {
            $process = $this->project->system()->runProcessWithCallbacks(
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
        } catch (BuildStalledException $e) {
            $logger->flushBuffers();
            $logger->error($e->getMessage());
            $this->stopTaggedStep($cmd, (string) $tag);
            throw $e;
        } finally {
            $logger->setPid(null);
            $logger->flushBuffers();
        }

        return $process;
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
                $ids = preg_split('/\s+/', trim($system->exec(
                    ['sudo', 'docker', 'ps', '-q', '--filter', 'label=' . self::STEP_LABEL . '=' . $tag],
                    [],
                    30
                ))) ?: [];
                $ids = array_values(array_filter($ids));
                if ($ids !== []) {
                    $system->runProcess(['sudo', 'docker', 'rm', '-f', ...$ids], [], 60);
                }
            }
        } catch (\Throwable $e) {
            $this->logger()?->warn('Could not stop what the stalled step left running: ' . $e->getMessage());
        }
    }

    /**
     * Is a silent step still working? Its container's CPU, from `docker stats`:
     * the account's DinD for a build inside it (BuildKit is not a child of the
     * exec, so the process tree says nothing), the tagged container for a host
     * build. A download stalled on the network sits near 0%, a compile at 100%.
     *
     * @param list<string> $cmd
     */
    public function stepIsBusy(array $cmd, string $tag): bool
    {
        $system = $this->project->system();
        $stats = ['sudo', 'docker', 'stats', '--no-stream', '--format', '{{.CPUPerc}}'];
        if ($this->isDindExec($cmd)) {
            $argv = ['sudo', 'docker', 'compose', '-f', $this->project->composeFilePath(), ...array_slice($stats, 2), 'dind'];
        } elseif (self::isHostDockerRun($cmd)) {
            $ids = preg_split('/\s+/', trim($system->exec(
                ['sudo', 'docker', 'ps', '-q', '--filter', 'label=' . self::STEP_LABEL . '=' . $tag],
                [],
                30
            ))) ?: [];
            $ids = array_values(array_filter($ids));
            if ($ids === []) {
                return false;
            }
            $argv = [...$stats, ...$ids];
        } else {
            return false;
        }

        return self::cpuPercent($system->exec($argv, [], 30)) >= self::BUSY_CPU_PERCENT;
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
