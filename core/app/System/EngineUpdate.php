<?php

namespace App\System;

use App\System as EngineSystem;
use Symfony\Component\Process\Process;

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

    /**
     * Marks the placeholder `latest` until the installer replaces it with its
     * own run directory. Holds the enqueue time, not a pid: there is no process yet.
     */
    public const PENDING_FILE = 'pending';

    /** After this a placeholder is a run that never started; a real one replaces it within seconds. */
    public const PENDING_GRACE_SECONDS = 300;

    public function __construct(private readonly EngineSystem $system) {}

    public function latestDir(): string
    {
        return self::LOGS_DIR.'/latest';
    }

    /**
     * What the most recent run did, or null when there has never been one.
     *
     * @return ?array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   failed: bool,
     *   reason: ?string,
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
        if (! $this->system->filesystem()->directoryExists($dir)) {
            return null;
        }

        return $this->describe($dir);
    }

    /**
     * `started_at` is the mtime of `pid` -- the one file a run writes once, at
     * its start; the `latest` symlink's own time moves whenever it is re-pointed.
     *
     * @return array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   failed: bool,
     *   reason: ?string,
     *   tail_stdout: ?string,
     *   tail_stderr: ?string,
     *   from_version: ?string,
     *   to_version: ?string,
     *   logs_path: string
     * }
     */
    private function describe(string $dir): array
    {
        $fs = $this->system->filesystem();
        $pid = $fs->cat("{$dir}/pid");
        $exitCode = self::exitCode($fs->cat("{$dir}/exit_code"));
        $failed = $exitCode === null ? ! $this->isRunning() : $exitCode !== 0;
        $tailStdout = self::stripAnsi($fs->tail("{$dir}/stdout", self::TAIL_LINES));
        $tailStderr = self::stripAnsi($fs->tail("{$dir}/stderr", self::TAIL_LINES));

        return [
            'started_at' => $fs->mtime("{$dir}/pid"),
            'finished_at' => $fs->mtime("{$dir}/exit_code"),
            'pid' => is_numeric($pid) ? (int) $pid : null,
            'exit_code' => $exitCode,
            'failed' => $failed,
            'reason' => $failed ? self::failureReason($exitCode, $pid, $tailStdout, $tailStderr) : null,
            'tail_stdout' => $tailStdout,
            'tail_stderr' => $tailStderr,
            'from_version' => $fs->cat("{$dir}/from_version"),
            'to_version' => $fs->cat("{$dir}/to_version"),
            'logs_path' => $dir,
        ];
    }

    /**
     * Why a failed run failed when neither stream says it: a placeholder that
     * never became a run has nothing, and a dead process leaves only its pid.
     */
    private static function failureReason(?int $exitCode, ?string $pid, ?string $stdout, ?string $stderr): ?string
    {
        if ($exitCode !== null || trim((string) $stdout) !== '' || trim((string) $stderr) !== '') {
            return null;
        }

        return is_numeric(trim((string) $pid))
            ? 'the update process ended without writing an exit code'
            : 'the update job never started';
    }

    /** A run is over once it has an exit code; a code that is not a number is not one. */
    private static function exitCode(?string $code): ?int
    {
        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * Leave a `latest` directory behind so {@see isRunning()} has something to
     * read before the script itself writes one, then start the updater in the
     * background.
     */
    public function run(?string $licenseKey = null): void
    {
        $this->seedPendingPlaceholder();
        $this->enqueueUpdater($licenseKey);
    }

    /** Hand `updater.sh` to `at` on the host, so it outlives this request. */
    private function enqueueUpdater(?string $licenseKey): void
    {
        $process = $this->system->runProcess(
            self::onHost('echo '.escapeshellarg($this->updaterJob($licenseKey)).' | at now')
        );

        if ($process->getExitCode() !== 0) {
            $this->failPlaceholder($process);
            throw new \Exception(
                'Failed to run update script: '
                .($process->getErrorOutput() ?: $process->getOutput())
                .' (exit code '.(string) $process->getExitCode().')'
            );
        }
    }

    /**
     * The updater's output, and its exit code when it fails, go to the placeholder: until the
     * installer writes its own run, nothing else records why an update never started.
     */
    private function updaterJob(?string $licenseKey): string
    {
        $updater = 'bash '.escapeshellarg($this->system->engineDirPath().'/updater.sh').' -f --background';
        if (! empty($licenseKey)) {
            $updater .= ' '.escapeshellarg($licenseKey);
        }
        $tmp = self::LOGS_DIR.'/tmp';

        return "{$updater} > {$tmp}/stdout 2> {$tmp}/stderr || echo \$? > {$tmp}/exit_code";
    }

    /** Point `latest` at an empty `tmp` run holding only the enqueue time, until the updater writes its own. */
    private function seedPendingPlaceholder(): void
    {
        $logs = self::LOGS_DIR;
        $this->system->runProcess(self::onHost(
            "mkdir -p {$logs}/tmp && date +%s > {$logs}/tmp/".self::PENDING_FILE
            ." && rm -f {$logs}/tmp/pid {$logs}/tmp/exit_code {$logs}/tmp/stdout {$logs}/tmp/stderr"
            ." && ln -sfn {$logs}/tmp {$logs}/latest"
        ));
    }

    /** A job `at` refused never runs, so the placeholder records the refusal as a failed run. */
    private function failPlaceholder(Process $process): void
    {
        $tmp = self::LOGS_DIR.'/tmp';
        $this->system->runProcess(self::onHost(
            'printf %s '.escapeshellarg($process->getErrorOutput() ?: $process->getOutput())." > {$tmp}/stderr"
            .' && echo '.(int) $process->getExitCode()." > {$tmp}/exit_code"
        ));
    }

    /**
     * Whether an updater this engine started is still alive, or its placeholder is within
     * the grace. A run that has written its exit code is over, whatever its pid now is.
     */
    public function isRunning(): bool
    {
        $dir = $this->latestDir();
        $fs = $this->system->filesystem();

        if (! $fs->directoryExists($dir) || self::exitCode($fs->cat("{$dir}/exit_code")) !== null) {
            return false;
        }

        $pending = trim((string) $fs->cat("{$dir}/".self::PENDING_FILE));
        if ($pending !== '') {
            return self::pendingAge($pending) < self::PENDING_GRACE_SECONDS;
        }

        $pid = trim((string) $fs->cat("{$dir}/pid"));
        if (! ctype_digit($pid)) {
            return false;
        }

        return $this->system->runProcess(self::onHost(['kill', '-0', $pid]))->getExitCode() === 0;
    }

    /** Seconds since the placeholder was seeded; an unreadable timestamp must not block an update. */
    private static function pendingAge(string $started): int
    {
        if (! ctype_digit($started)) {
            return PHP_INT_MAX;
        }

        return max(0, time() - (int) $started);
    }

    /**
     * The updater lives outside this container, so every command enters the
     * host's namespaces first.
     *
     * @param  string|list<string>  $command
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
