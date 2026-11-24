<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\EngineIdentity;
use App\Lib\Hub\HubHostFacts;
use App\Lib\Hub\HubReporter;
use App\Lib\Hub\HubSecretRotation;
use App\Lib\Hub\HubUpgrade;
use App\Models\HubConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Every minute: report to PanelAlpha Connect when the interval the Hub asked for has passed, at a second of the minute
 * fixed by this engine's id so engines spread out. A connection made before push existed upgrades itself first.
 */
class HubReportCommand extends Command
{
    protected $signature = 'hub:report {--now : Report at once, without waiting for this engine\'s second or the interval}';

    protected $description = 'Send this engine\'s minute report to PanelAlpha Connect';

    private const LOCK = 'hub:report:send';

    private const LOCK_SECONDS = 30;

    /** A per-minute schedule starts a little late; an interval this close to over counts as over. */
    private const DUE_SLACK_SECONDS = 5;

    public function handle(): int
    {
        $connection = HubConnection::active();
        if ($connection === null) {
            return self::SUCCESS;
        }
        if (HubUpgrade::isDue($connection)) {
            HubUpgrade::attempt($connection);
        }
        if (! $connection->isPush()) {
            return self::SUCCESS;
        }

        $this->refreshHostFacts($connection);
        $now = (bool) $this->option('now');
        if (! $now) {
            $this->waitForOwnSecond();
        }
        if (! $now && ! self::isDue($connection->refresh())) {
            return self::SUCCESS;
        }

        Cache::lock(self::LOCK, self::LOCK_SECONDS)->get(function () use ($connection): void {
            $response = (new HubReporter)->send($connection);
            $this->line($response === null ? 'PanelAlpha Connect could not be reached.' : "Reported: HTTP {$response->status}.");
            $connection = HubConnection::active();
            if ($connection?->rotate_secret) {
                HubSecretRotation::rotate($connection);
            }
        });

        return self::SUCCESS;
    }

    public static function isDue(HubConnection $connection): bool
    {
        if ($connection->report_not_before?->isFuture()) {
            return false;
        }

        return $connection->last_report_at === null
            || $connection->last_report_at->copy()->addSeconds($connection->report_interval_seconds - self::DUE_SLACK_SECONDS)->lte(now());
    }

    public static function offsetSecond(): int
    {
        return crc32(EngineIdentity::appId()) % 60;
    }

    private function waitForOwnSecond(): void
    {
        $wait = self::offsetSecond() - now()->second;
        if ($wait > 0) {
            Sleep::for($wait)->seconds();
        }
    }

    /** Measuring the host is live work, so it happens here and the report only reads what was stored. */
    private function refreshHostFacts(HubConnection $connection): void
    {
        try {
            HubHostFacts::refreshIfStale($connection);
        } catch (\Throwable $e) {
            Log::warning('Could not measure the host for PanelAlpha Connect: '.$e->getMessage());
        }
    }
}
