<?php

namespace App\Lib\Deploy\Telemetry;

use App\System;
use App\System\ProcessRunner;

/**
 * The outbox: reports waiting to be sent.
 *
 * Telemetry is written during a deploy and sent later, by the scheduler. That
 * split is not an optimisation, it is the design. `QUEUE_CONNECTION` defaults
 * to `sync` on this engine, so a dispatched job would run *inside* the deploy
 * request — a request that already carries a 3600s build and a customer waiting
 * on an HTTP response. Nothing about reporting a failure is worth adding a
 * network round trip, a DNS timeout or a TLS handshake to that path.
 *
 * So capture writes one small file and returns. `telemetry:ship` drains the
 * directory on a schedule. The queue survives a reboot, an offline box, and an
 * ingest endpoint that is down for a week.
 *
 * One exception: a failed deploy's report is sent at once with a short
 * timeout ({@see TelemetryShipper::sendNow()}), because monitoring may answer
 * with the fix. It lands here only when that send does not get through.
 *
 * Every method swallows its own errors and reports failure by return value. A
 * full disk is exactly when deploys fail, and exactly when telemetry must not
 * make it worse.
 *
 * Ownership: the engine runs as www-data (php-fpm, cron) or root (`pae` /
 * `docker compose exec`). Cron ships as www-data, so every mkdir and write
 * chowns to www-data — the same pattern {@see \App\Lib\Deploy\DeployLog\LogStorage}
 * uses for deploy logs.
 */
class Spool
{
    /** Reports kept before the oldest are dropped. */
    public const MAX_FILES = 500;

    /** Attempts before a report is given up on. */
    public const MAX_ATTEMPTS = 8;

    private const DIRECTORY_MODE = 0775;

    private const OWNER = 'www-data:www-data';

    private string $dir;

    private ?ProcessRunner $processes;

    /**
     * @param ?ProcessRunner $processes only the chown below needs it; built on
     *                                  demand so no caller has to supply one.
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
     * Where a report's source bundle lives, if it has one.
     *
     * A sibling of the report rather than a field inside it: the zip is written
     * during the deploy, when the source still exists on disk, and the report
     * JSON is written straight after. Keeping them as two files means a bundle
     * that fails to build costs the report nothing.
     */
    public function bundlePathFor(string $id): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
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

    /**
     * Hand a path under the spool to www-data so cron can ship it.
     *
     * Used for source-bundle zips written outside {@see writeAtomic()}.
     */
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
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }

        if (!$this->ensureDir()) {
            return null;
        }

        $envelope = [
            'report' => $report,
            'attempts' => 0,
            'first_queued_at' => time(),
            'last_attempt_at' => null,
        ];

        $path = $this->dir . '/' . $id . '.json';
        if (!$this->writeAtomic($path, (string) json_encode($envelope))) {
            return null;
        }

        $this->prune();

        return $path;
    }

    /**
     * How long to wait before retrying a report that failed to send.
     *
     * Doubling from a minute, capped at six hours. An ingest endpoint that is
     * down for a weekend should cost an install a handful of requests, not one
     * every five minutes for sixty hours.
     */
    public static function backoffSeconds(int $attempts): int
    {
        if ($attempts < 1) {
            return 0;
        }

        return (int) min(6 * 3600, 60 * (2 ** min($attempts, 10)));
    }

    /**
     * Queued reports, oldest first, whose backoff has elapsed.
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
                // Unreadable, corrupt, or readable JSON with no report inside
                // it. The last one is the dangerous shape: it would ship as a
                // payload with no id, no outcome and no fingerprint, the
                // ingest would refuse the *batch* as malformed, and a 4xx is
                // permanent -- so one hollow file takes every good report
                // batched with it. Drop it here instead.
                @unlink($path);
                continue;
            }
            $attempts = (int) ($envelope['attempts'] ?? 0);
            $deferrals = (int) ($envelope['deferrals'] ?? 0);
            $lastAttempt = $envelope['last_attempt_at'] ?? null;
            if (is_int($lastAttempt) && $now < $lastAttempt + self::backoffSeconds($attempts + $deferrals)) {
                continue;
            }
            $id = (string) ($envelope['report']['id'] ?? basename($path, '.json'));
            $bundle = $this->bundlePathFor($id);
            $items[] = [
                'path' => $path,
                'id' => $id,
                'attempts' => $attempts,
                'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
                'bundle' => $bundle !== null && is_file($bundle) ? $bundle : null,
            ];
        }

        return $items;
    }

    /**
     * Is there a report in here the ingest could actually interpret?
     *
     * `id` and `outcome` are the two fields every consumer keys on -- one is
     * the idempotency key, the other is the event itself. A file missing
     * either is not a report that lost some detail; it is not a report.
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
     * Drop a report and any source bundle that belongs to it.
     *
     * The bundle goes with the report unconditionally. It is the customer's
     * source code sitting on their own disk waiting to be uploaded: once the
     * report it belongs to is settled, there is nothing that will ever send it,
     * and leaving it behind is just an unattended copy of their repository.
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
     * Record a failed send. Returns false when the report has been given up on
     * and removed.
     *
     * Both give-up paths go through forget() rather than unlink(), so a source
     * bundle dies with the report it belongs to. Deleting only the report would
     * leave the customer's zipped source on their disk with nothing left that
     * would ever send or clean it up.
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
     * Record a send that never reached an ingest.
     *
     * Backs the report off exactly like a failure, but does not spend one of
     * its {@see self::MAX_ATTEMPTS}: those exist to stop retrying a payload a
     * server has looked at and refused, and a socket that never opened is not
     * that. An ingest that does not resolve -- one being stood up, one an operator
     * has firewalled off -- must cost reports nothing but delay, or telemetry
     * destroys its own evidence precisely when nobody is receiving it.
     *
     * MAX_FILES still bounds the spool, so holding is not unbounded.
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
        $bytes = 0;
        $oldest = null;
        foreach ($files as $path) {
            $bytes += (int) @filesize($path);
            $mtime = @filemtime($path);
            if ($mtime !== false && ($oldest === null || $mtime < $oldest)) {
                $oldest = $mtime;
            }
        }

        // Bundles are counted separately: they are megabytes where reports are
        // kilobytes, and an operator asking what telemetry costs them in disk
        // is really asking about these.
        $bundles = @glob($this->dir . '/*.zip') ?: [];
        $bundleBytes = 0;
        foreach ($bundles as $bundle) {
            $bundleBytes += (int) @filesize($bundle);
        }

        return [
            'count' => count($files),
            'bytes' => $bytes,
            'oldest' => $oldest,
            'bundles' => count($bundles),
            'bundle_bytes' => $bundleBytes,
        ];
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
     * Cap the outbox. An install that has been offline for a month must not
     * fill its own disk with reports about deploys nobody will read.
     *
     * Also sweeps orphaned bundles — a zip whose report was pruned, or whose
     * report never got written because the disk filled between the two. Nothing
     * will ever send those, and they are copies of customer source code.
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

        // `pae` mkdir -p as root leaves storage/app and telemetry as root:700;
        // cron (www-data) cannot traverse those parents. Claim the outbox and
        // its parents up through …/storage/app (outbox → telemetry → app).
        $this->fixOwnership($this->dir);
        $telemetry = dirname($this->dir);
        $app = dirname($telemetry);
        if ($telemetry !== '' && $telemetry !== '/' && $telemetry !== $this->dir) {
            $this->fixOwnership($telemetry);
        }
        if ($app !== '' && $app !== '/' && basename($app) === 'app') {
            $this->fixOwnership($app);
        }

        return true;
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

    private function fixOwnership(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        // Best-effort: unit tests have no Laravel app / sudo rule. A failed
        // chown must not fail put() — cron simply will not see a root-owned
        // file, which is the same outcome as not trying.
        try {
            $this->processes()->runProcess(['sudo', 'chown', '-R', self::OWNER, $path]);
        } catch (\Throwable) {
            // ignore
        }
    }
}
