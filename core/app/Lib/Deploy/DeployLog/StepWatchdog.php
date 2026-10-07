<?php

namespace App\Lib\Deploy\DeployLog;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use Symfony\Component\Process\Process;

/**
 * Kills a deploy step that has gone silent for too long. Deploys share one
 * worker, so a hung step would otherwise block every account until its
 * overall timeout. Any output resets the clock, and so does a step that is
 * silent but still using CPU: rustc on a workspace's last crate prints nothing
 * for longer than the limit.
 */
class StepWatchdog
{
    /** The `build-stalled` explainer rule matches on this prefix. */
    public const MARKER = 'PANELALPHA: build step stalled';

    /** The `disk-limit-reached` explainer rule matches on this prefix. */
    public const DISK_MARKER = 'PANELALPHA: disk limit reached';

    /** `du` over a large Docker store takes seconds, so not every poll. */
    private const DISK_CHECK_SECONDS = 15;

    private const POLL_MICROSECONDS = 100_000;

    private const GRACE_SECONDS = 5;

    private const MAX_LABEL = 160;

    private const MAX_LINE = 200;

    /** When the clock was last reset, by output or by the step being busy. */
    private float $lastOutputAt;

    /** When the step last printed anything; what the message reports. */
    private float $lastPrintedAt;

    private string $lastLine = '';

    private string $partial = '';

    private float $lastDiskCheckAt;

    /**
     * @param ?\Closure(): bool $busy asked once the limit is reached; true means
     *        the step is working, not hung, and gets another window
     * @param ?\Closure(): ?string $diskFull asked every DISK_CHECK_SECONDS; a
     *        reason stops the step, so a pull cannot fill the host
     */
    public function __construct(
        private readonly int $idleSeconds,
        private readonly string $label,
        private readonly ?\Closure $busy = null,
        private readonly ?\Closure $diskFull = null,
        private readonly int $diskCheckSeconds = self::DISK_CHECK_SECONDS
    ) {
        $this->lastOutputAt = $this->lastPrintedAt = $this->lastDiskCheckAt = microtime(true);
    }

    /**
     * Output callback to pass to Process::start(); records activity, then
     * forwards to $onOutput.
     */
    public function watch(?callable $onOutput = null): callable
    {
        $this->lastOutputAt = $this->lastPrintedAt = microtime(true);

        return function (string $type, string $data) use ($onOutput): void {
            $this->lastOutputAt = $this->lastPrintedAt = microtime(true);
            $this->remember($data);
            if ($onOutput !== null) {
                $onOutput($type, $data);
            }
        };
    }

    /**
     * Wait for a process started with watch()'s callback. The overall timeout
     * still applies; silence past the limit kills the tree and throws.
     *
     * @throws BuildStalledException
     * @throws DiskLimitException
     */
    public function wait(Process $process): int
    {
        while ($process->isRunning()) {
            $process->checkTimeout();
            $this->stopIfDiskFull($process);
            $silent = microtime(true) - $this->lastOutputAt;
            if ($this->idleSeconds > 0 && $silent >= $this->idleSeconds) {
                if ($this->isBusy()) {
                    $this->lastOutputAt = microtime(true);
                    continue;
                }
                // Before the kill: killTree() polls the process, and what it
                // prints while dying would reset the silence and the last line.
                $message = $this->message((int) round(microtime(true) - $this->lastPrintedAt));
                self::killTree($process);
                throw new BuildStalledException($message);
            }
            usleep(self::POLL_MICROSECONDS);
        }

        return $process->wait();
    }

    /** @throws DiskLimitException */
    private function stopIfDiskFull(Process $process): void
    {
        if ($this->diskFull === null || microtime(true) - $this->lastDiskCheckAt < $this->diskCheckSeconds) {
            return;
        }
        $this->lastDiskCheckAt = microtime(true);
        try {
            $why = ($this->diskFull)();
        } catch (\Throwable) {
            // A reading that cannot be taken is not a reason to stop a step.
            return;
        }
        if (!is_string($why) || $why === '') {
            return;
        }

        self::killTree($process);
        throw new DiskLimitException(self::DISK_MARKER . " ({$why})");
    }

    /** A probe that cannot answer says "not busy": the limit then holds as before. */
    private function isBusy(): bool
    {
        if ($this->busy === null) {
            return false;
        }
        try {
            return ($this->busy)() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function message(int $silentSeconds): string
    {
        $label = self::shorten($this->label, self::MAX_LABEL);
        $line = $this->lastLine === '' ? '(none)' : '"' . self::shorten($this->lastLine, self::MAX_LINE) . '"';

        return self::MARKER . ": \"{$label}\" printed nothing for {$silentSeconds}s. Last output: {$line}";
    }

    /**
     * SIGTERM the process, its descendants and every process group they lead,
     * then SIGKILL whatever is left after a grace period. Our own group is
     * skipped: it is the worker itself.
     */
    public static function killTree(Process $process): void
    {
        $pid = $process->getPid();
        if ($pid === null) {
            return;
        }

        $table = self::processTable();
        $pids = [$pid, ...self::descendants($pid, $table)];
        $own = posix_getpgrp();
        $groups = [];
        foreach ($pids as $p) {
            $group = $table[$p]['pgrp'] ?? null;
            if ($group !== null && $group > 1 && $group !== $own) {
                $groups[$group] = true;
            }
        }
        $groups = array_keys($groups);

        self::signal($pids, $groups, 15);
        $deadline = microtime(true) + self::GRACE_SECONDS;
        while (microtime(true) < $deadline && ($process->isRunning() || self::anyAlive($pids, $pid))) {
            usleep(self::POLL_MICROSECONDS);
        }
        if ($process->isRunning() || self::anyAlive($pids, $pid)) {
            self::signal($pids, $groups, 9);
        }
        $process->stop(0);
    }

    private function remember(string $data): void
    {
        // \r-separated progress bars count as lines too.
        $lines = preg_split('/[\r\n]+/', $this->partial . $data) ?: [];
        $this->partial = (string) array_pop($lines);
        foreach ([...$lines, $this->partial] as $line) {
            if (trim($line) !== '') {
                $this->lastLine = trim($line);
            }
        }
    }

    /**
     * @param list<int> $pids
     * @param list<int> $groups
     */
    private static function signal(array $pids, array $groups, int $signal): void
    {
        foreach ($groups as $group) {
            @posix_kill(-$group, $signal);
        }
        foreach ($pids as $p) {
            @posix_kill($p, $signal);
        }
    }

    /**
     * @param list<int> $pids
     */
    private static function anyAlive(array $pids, int $except): bool
    {
        foreach ($pids as $p) {
            if ($p === $except) {
                continue;
            }
            $state = self::processTable([$p])[$p]['state'] ?? null;
            if ($state !== null && $state !== 'Z') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{ppid: int, pgrp: int, state: string}> $table
     * @return list<int>
     */
    private static function descendants(int $pid, array $table): array
    {
        $found = [];
        $queue = [$pid];
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach ($table as $child => $info) {
                if ($info['ppid'] === $parent && !in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * ppid, process group and state from /proc/<pid>/stat.
     *
     * @param list<int>|null $only
     * @return array<int, array{ppid: int, pgrp: int, state: string}>
     */
    private static function processTable(?array $only = null): array
    {
        $paths = $only === null
            ? (glob('/proc/[0-9]*/stat') ?: [])
            : array_map(static fn (int $p): string => "/proc/{$p}/stat", $only);

        $table = [];
        foreach ($paths as $path) {
            $stat = @file_get_contents($path);
            if (!is_string($stat) || ($close = strrpos($stat, ')')) === false) {
                continue;
            }
            // The command name may contain spaces and parens; fields follow the last ')'.
            $fields = explode(' ', trim(substr($stat, $close + 2)));
            if (count($fields) < 3) {
                continue;
            }
            $table[(int) basename(dirname($path))] = [
                'state' => $fields[0],
                'ppid' => (int) $fields[1],
                'pgrp' => (int) $fields[2],
            ];
        }

        return $table;
    }

    private static function shorten(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
