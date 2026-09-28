<?php

namespace App\System;

use App\System as EngineSystem;

/**
 * Updating the engine itself: starting `updater.sh`, and reading back what the
 * last run did.
 *
 * The updater runs detached through `at`, so nothing here waits for it. What it
 * leaves behind is a directory of small files -- pid, exit code, the two output
 * streams, the versions -- symlinked to as `latest`, and reading those is the
 * only way to report progress.
 */
final class EngineUpdate
{
    public const LOGS_DIR = '/opt/panelalpha/log/engine-updates';

    /** How many trailing lines of each stream are reported. */
    private const TAIL_LINES = 2;

    public function __construct(private readonly EngineSystem $system)
    {
    }

    public function latestDir(): string
    {
        return self::LOGS_DIR . '/latest';
    }

    /**
     * What the most recent run did, or null when there has never been one.
     *
     * @return ?array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   tail_stdout: ?string,
     *   tail_stderr: ?string,
     *   from_version: ?string,
     *   to_version: ?string,
     *   logs_path: string
     * }
     */
    public function latest(): ?array
    {
        $dir = $this->latestDir();
        $fs = $this->system->filesystem();

        if (!$fs->directoryExists($dir)) {
            return null;
        }

        $pid = $fs->cat("{$dir}/pid");
        $exitCode = $fs->cat("{$dir}/exit_code");

        return [
            'started_at' => $fs->mtime($dir),
            'finished_at' => $fs->mtime("{$dir}/exit_code"),
            'pid' => is_numeric($pid) ? (int) $pid : null,
            'exit_code' => is_numeric($exitCode) ? (int) $exitCode : null,
            'tail_stdout' => self::stripAnsi($fs->tail("{$dir}/stdout", self::TAIL_LINES)),
            'tail_stderr' => self::stripAnsi($fs->tail("{$dir}/stderr", self::TAIL_LINES)),
            'from_version' => $fs->cat("{$dir}/from_version"),
            'to_version' => $fs->cat("{$dir}/to_version"),
            'logs_path' => $dir,
        ];
    }

    /**
     * Start the updater in the background and leave a `latest` directory behind
     * so {@see isRunning()} has something to read before the script itself
     * writes one.
     */
    public function run(?string $licenseKey = null): void
    {
        $updater = 'bash ' . escapeshellarg($this->system->engineDirPath() . '/updater.sh') . ' -f --background';
        if (!empty($licenseKey)) {
            $updater .= ' ' . escapeshellarg($licenseKey);
        }

        $process = $this->system->runProcess(
            self::onHost('echo ' . escapeshellarg($updater) . ' | at now')
        );

        if ($process->getExitCode() !== 0) {
            throw new \Exception(
                'Failed to run update script: '
                . ($process->getErrorOutput() ?: $process->getOutput())
                . ' (exit code ' . (string) $process->getExitCode() . ')'
            );
        }

        $logs = self::LOGS_DIR;
        $this->system->runProcess(self::onHost(
            "mkdir -p {$logs}/tmp && echo '2' > {$logs}/tmp/pid && ln -sfn {$logs}/tmp {$logs}/latest"
        ));
    }

    /** Whether the pid the last run recorded is still alive. */
    public function isRunning(): bool
    {
        $dir = $this->latestDir();

        if (!$this->system->filesystem()->directoryExists($dir)) {
            return false;
        }

        $pidFile = "{$dir}/pid";
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = trim((string) file_get_contents($pidFile));
        if (!ctype_digit($pid)) {
            return false;
        }

        return $this->system->runProcess(self::onHost(['kill', '-0', $pid]))->getExitCode() === 0;
    }

    /**
     * The updater lives outside this container, so every command enters the
     * host's namespaces first.
     *
     * @param string|list<string> $command
     *
     * @return list<string>
     */
    private static function onHost(string|array $command): array
    {
        $argv = ['sudo', 'nsenter', '--target', '1', '--all'];

        return is_array($command)
            ? [...$argv, ...$command]
            : [...$argv, 'bash', '-c', $command];
    }

    /** Terminal colour codes are noise in a JSON field. */
    private static function stripAnsi(?string $text): ?string
    {
        return $text === null || $text === ''
            ? $text
            : preg_replace('/\x1B\[[0-9;]*[mK]/', '', $text);
    }
}
