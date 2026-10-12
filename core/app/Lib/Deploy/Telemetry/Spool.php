<?php

namespace App\Lib\Deploy\Telemetry;

use App\System;
use App\System\ProcessRunner;

/**
 * The outbox: reports written during a deploy and sent later by `telemetry:ship`,
 * so a deploy request (`QUEUE_CONNECTION` is `sync`) never waits on the network.
 * A failed deploy's report is sent at once ({@see TelemetryShipper::sendNow()})
 * and lands here only when that send does not get through.
 *
 * Every method swallows its own errors and reports failure by return value: a
 * full disk is exactly when deploys fail. Cron ships as www-data, so every mkdir
 * and write chowns to www-data, as {@see \App\Lib\Deploy\DeployLog\LogStorage} does.
 */
class Spool
{
    /** Reports kept before the oldest are dropped. */
    public const MAX_FILES = 500;

    /** Attempts before a report is given up on. */
    public const MAX_ATTEMPTS = 8;

    private const DIRECTORY_MODE = 0775;

    private const OWNER = 'www-data:www-data';

    private const VALID_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    private string $dir;

    private ?ProcessRunner $processes;

    /**
     * @param ?ProcessRunner $processes for chown only; built on demand when null
     */
    public function __construct(string $dir, ?ProcessRunner $processes = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->processes = $processes;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Where a report's source bundle lives: a sibling file, so a bundle that
     * fails to build costs the report nothing.
     */
    public function bundlePathFor(string $id): ?string
    {
        if (!preg_match(self::VALID_ID, $id)) {
            return null;
        }

        return $this->dir . '/' . $id . '.zip';
    }

    /**
     * Make the spool directory. Public because a source bundle is written into
     * it before the report that describes it.
     */
    public function ensureDirectory(): bool
    {
        return $this->ensureDir();
    }

    /** Hand a source bundle written outside {@see writeAtomic()} to www-data so cron can ship it. */
    public function claimOwnership(string $path): void
    {
        $this->fixOwnership($path);
    }

    /**
     * Queue a report. Returns the file path, or null when it could not be
     * written.
     *
     * @param array<string, mixed> $report
     */
    public function put(array $report): ?string
    {
        $id = $report['id'] ?? null;
        if (!is_string($id) || !preg_match(self::VALID_ID, $id)) {
            return null;
        }

        if (!$this->ensureDir()) {
            return null;
        }

        $encoded = self::encodedEnvelope($report);
        if ($encoded === null) {
            return null;
        }

        $path = $this->dir . '/' . $id . '.json';
        if (!$this->writeAtomic($path, $encoded)) {
            return null;
        }

        $this->prune();

        return $path;
    }

    /**
     * A new report's envelope as JSON. Raw stderr or a branch name need not be
     * UTF-8, so invalid bytes are substituted rather than failing the encode.
     *
     * @param array<string, mixed> $report
     */
    private static function encodedEnvelope(array $report): ?string
    {
        $envelope = [
            'report' => $report,
            'attempts' => 0,
            'first_queued_at' => time(),
            'last_attempt_at' => null,
        ];

        try {
            return json_encode($envelope, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * How long to wait before retrying a report that failed to send: doubling
     * from a minute, capped at six hours.
     */
    public static function backoffSeconds(int $attempts): int
    {
        if ($attempts < 1) {
            return 0;
        }

        return (int) min(6 * 3600, 60 * (2 ** min($attempts, 10)));
    }

    /**
     * Queued reports, oldest first, whose backoff has elapsed. An unreadable or
     * hollow file is dropped with its bundle: the ingest would refuse its whole batch.
     *
     * @return list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>
     */
    public function pending(int $limit = 100, ?int $now = null): array
    {
        $now ??= time();
        $files = $this->files();
        $items = [];

        foreach ($files as $path) {
            if (count($items) >= $limit) {
                break;
            }
            $envelope = $this->read($path);
            if ($envelope === null || !self::isSendable($envelope)) {
                $this->forget($path);
                continue;
            }
            if (self::isBackingOff($envelope, $now)) {
                continue;
            }
            $items[] = $this->pendingItem($path, $envelope);
        }

        return $items;
    }

    /** @param array<string, mixed> $envelope */
    private static function isBackingOff(array $envelope, int $now): bool
    {
        $attempts = (int) ($envelope['attempts'] ?? 0);
        $deferrals = (int) ($envelope['deferrals'] ?? 0);
        $lastAttempt = $envelope['last_attempt_at'] ?? null;

        return is_int($lastAttempt) && $now < $lastAttempt + self::backoffSeconds($attempts + $deferrals);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}
     */
    private function pendingItem(string $path, array $envelope): array
    {
        $id = (string) ($envelope['report']['id'] ?? basename($path, '.json'));
        $bundle = $this->bundlePathFor($id);

        return [
            'path' => $path,
            'id' => $id,
            'attempts' => (int) ($envelope['attempts'] ?? 0),
            'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
            'bundle' => $bundle !== null && is_file($bundle) ? $bundle : null,
        ];
    }

    /**
     * Whether the envelope holds a report the ingest can interpret: `id` (the
     * idempotency key) and `outcome` (the event) are both non-empty strings.
     *
     * @param array<string, mixed> $envelope
     */
    private static function isSendable(array $envelope): bool
    {
        $report = $envelope['report'] ?? null;

        return is_array($report)
            && is_string($report['id'] ?? null) && $report['id'] !== ''
            && is_string($report['outcome'] ?? null) && $report['outcome'] !== '';
    }

    /**
     * Drop a report and, unconditionally, its source bundle: once the report is
     * settled nothing will send that copy of the customer's source.
     */
    public function forget(string $path): void
    {
        @unlink($path);
        $this->forgetBundle(basename($path, '.json'));
    }

    public function forgetBundle(string $id): void
    {
        $bundle = $this->bundlePathFor($id);
        if ($bundle !== null && is_file($bundle)) {
            @unlink($bundle);
        }
    }

    /**
     * Record a failed send. False when the report has been given up on and
     * removed together with its source bundle.
     */
    public function recordFailure(string $path): bool
    {
        $envelope = $this->read($path);
        if ($envelope === null) {
            $this->forget($path);

            return false;
        }

        $attempts = (int) ($envelope['attempts'] ?? 0) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->forget($path);

            return false;
        }

        $envelope['attempts'] = $attempts;
        $envelope['last_attempt_at'] = time();
        $this->writeAtomic($path, (string) json_encode($envelope));

        return true;
    }

    /**
     * Record a send that never reached an ingest: backed off like a failure but
     * not counted against {@see self::MAX_ATTEMPTS}, which are for payloads a
     * server refused. {@see self::MAX_FILES} still bounds the spool.
     */
    public function recordDeferral(string $path): void
    {
        $envelope = $this->read($path);
        if ($envelope === null) {
            $this->forget($path);

            return;
        }

        $envelope['deferrals'] = (int) ($envelope['deferrals'] ?? 0) + 1;
        $envelope['last_attempt_at'] = time();
        $this->writeAtomic($path, (string) json_encode($envelope));
    }

    /**
     * @return array{count: int, bytes: int, oldest: ?int, bundles: int, bundle_bytes: int}
     */
    public function stats(): array
    {
        $files = $this->files();
        [$bytes, $oldest] = self::sizeAndOldest($files);
        [$bundles, $bundleBytes] = $this->bundleUsage();

        return [
            'count' => count($files),
            'bytes' => $bytes,
            'oldest' => $oldest,
            'bundles' => count($bundles),
            'bundle_bytes' => $bundleBytes,
        ];
    }

    /**
     * @param list<string> $files
     * @return array{0: int, 1: ?int} total bytes, oldest mtime
     */
    private static function sizeAndOldest(array $files): array
    {
        $bytes = 0;
        $oldest = null;
        foreach ($files as $path) {
            $bytes += (int) @filesize($path);
            $mtime = @filemtime($path);
            if ($mtime !== false && ($oldest === null || $mtime < $oldest)) {
                $oldest = $mtime;
            }
        }

        return [$bytes, $oldest];
    }

    /**
     * Source bundles, counted apart from reports: they are megabytes where
     * reports are kilobytes, so they are what telemetry costs in disk.
     *
     * @return array{0: list<string>, 1: int} bundle paths, total bytes
     */
    private function bundleUsage(): array
    {
        $bundles = @glob($this->dir . '/*.zip') ?: [];
        $bundleBytes = 0;
        foreach ($bundles as $bundle) {
            $bundleBytes += (int) @filesize($bundle);
        }

        return [$bundles, $bundleBytes];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Oldest first. The ids are ULIDs, so lexical order is chronological.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $found = @glob($this->dir . '/*.json');
        if ($found === false) {
            return [];
        }
        sort($found);

        return array_values($found);
    }

    /**
     * Cap the outbox at {@see MAX_FILES}, oldest first, and sweep bundles whose
     * report is gone or was never written: nothing will ever send those.
     */
    private function prune(): void
    {
        $files = $this->files();
        $excess = count($files) - self::MAX_FILES;
        if ($excess > 0) {
            foreach (array_slice($files, 0, $excess) as $path) {
                $this->forget($path);
            }
        }

        foreach (@glob($this->dir . '/*.zip') ?: [] as $bundle) {
            if (!is_file($this->dir . '/' . basename($bundle, '.zip') . '.json')) {
                @unlink($bundle);
            }
        }
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, self::DIRECTORY_MODE, true) && !is_dir($this->dir)) {
                return false;
            }
        }

        if (!is_dir($this->dir)) {
            return false;
        }

        $this->claimOutboxAndParents();

        return true;
    }

    /**
     * Root's `mkdir -p` leaves telemetry and storage/app root:700, which cron cannot
     * traverse: claim them too, but only in that layout, never a custom spool dir's parent.
     */
    private function claimOutboxAndParents(): void
    {
        $this->fixOwnership($this->dir);
        $telemetry = dirname($this->dir);
        if (basename($telemetry) !== 'telemetry') {
            return;
        }
        $this->fixOwnership($telemetry);
        $app = dirname($telemetry);
        if (basename($app) === 'app') {
            $this->fixOwnership($app);
        }
    }

    private function writeAtomic(string $path, string $contents): bool
    {
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        $this->fixOwnership($path);

        return true;
    }

    private function processes(): ProcessRunner
    {
        return $this->processes ??= new System();
    }

    /**
     * Best-effort chown to www-data: when it fails (no sudo rule, no Laravel app)
     * cron just cannot see the file, the same as not trying.
     */
    private function fixOwnership(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        try {
            $this->processes()->runProcess(['sudo', 'chown', '-R', self::OWNER, $path]);
        } catch (\Throwable) {
        }
    }
}
