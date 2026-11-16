<?php

namespace App\System;

use App\System as EngineSystem;
use Symfony\Component\Process\Process;

/**
 * Updating the engine: starting `updater.sh` detached through `at`, so nothing here waits for it, and reading back the
 * small files it leaves under `latest` (pid, exit code, output, versions, result, reason), the only way to report
 * progress.
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

    /** The pid older engines seeded their placeholder with: the host's kthreadd, always alive and never an updater. */
    private const LEGACY_PLACEHOLDER_PID = '2';

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
     *   result: ?string,
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
     * A run that died without an exit code finished when it last wrote anything.
     *
     * @return array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   failed: bool,
     *   result: ?string,
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
        $died = $failed && $exitCode === null;
        $recordedReason = self::field($fs->cat("{$dir}/reason"));

        return [
            'started_at' => $fs->mtime("{$dir}/pid"),
            'finished_at' => $died ? $this->lastWrite($dir) : $fs->mtime("{$dir}/exit_code"),
            'pid' => is_numeric($pid) ? (int) $pid : null,
            'exit_code' => $exitCode,
            'failed' => $failed,
            'result' => self::field($fs->cat("{$dir}/result")),
            'reason' => $recordedReason ?? ($failed ? self::failureReason($exitCode, $pid) : null),
            'tail_stdout' => self::stripAnsi($fs->tail("{$dir}/stdout", self::TAIL_LINES)),
            'tail_stderr' => self::stripAnsi($fs->tail("{$dir}/stderr", self::TAIL_LINES)),
            'from_version' => self::field($fs->cat("{$dir}/from_version")),
            'to_version' => self::field($fs->cat("{$dir}/to_version")),
            'logs_path' => $dir,
        ];
    }

    /** When a run that left no exit code last wrote anything: its output, else its start. */
    private function lastWrite(string $dir): ?int
    {
        $fs = $this->system->filesystem();

        return $fs->mtime("{$dir}/stdout")
            ?? $fs->mtime("{$dir}/pid")
            ?? $fs->mtime("{$dir}/".self::PENDING_FILE);
    }

    /**
     * Why a failed run failed when it recorded no reason itself: a non-zero exit,
     * a process that died before writing one, or a job that never started.
     */
    private static function failureReason(?int $exitCode, ?string $pid): string
    {
        if ($exitCode !== null) {
            return "the update exited with code {$exitCode}";
        }

        return is_numeric(trim((string) $pid))
            ? 'the update process ended without writing an exit code'
            : 'the update job never started';
    }

    /** A one-line file the run wrote; empty or missing is not known. */
    private static function field(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** A run is over once it has an exit code; a code that is not a number is not one. */
    private static function exitCode(?string $code): ?int
    {
        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * Leave a `latest` directory behind so {@see isRunning()} has something to read before the script itself
     * writes one, then start the updater in the background.
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
            Privileged::host()->enqueueEngineUpdate($this->system->engineDirPath(), $licenseKey)
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

    /** Point `latest` at an empty `tmp` run holding only the enqueue time, until the updater writes its own. */
    private function seedPendingPlaceholder(): void
    {
        $this->system->runProcess(Privileged::host()->seedEngineUpdatePlaceholder());
    }

    /** A job `at` refused never runs, so the placeholder records the refusal as a failed run. */
    private function failPlaceholder(Process $process): void
    {
        $this->system->runProcess(Privileged::host()->recordEngineUpdateRefusal(
            $process->getErrorOutput() ?: $process->getOutput(),
            (int) $process->getExitCode(),
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
        if (! ctype_digit($pid) || (int) $pid < 1 || $pid === self::LEGACY_PLACEHOLDER_PID) {
            return false;
        }

        return $this->system->runProcess(Privileged::host()->processExists((int) $pid))->getExitCode() === 0;
    }

    /** Seconds since the placeholder was seeded; an unreadable timestamp must not block an update. */
    private static function pendingAge(string $started): int
    {
        if (! ctype_digit($started)) {
            return PHP_INT_MAX;
        }

        return max(0, time() - (int) $started);
    }

    /** Terminal colour codes are noise in a JSON field. */
    private static function stripAnsi(?string $text): ?string
    {
        return $text === null || $text === ''
            ? $text
            : preg_replace('/\x1B\[[0-9;]*[mK]/', '', $text);
    }
}
