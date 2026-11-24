<?php

namespace App\Lib\Hub;

use App\Jobs\SendHubBackfill;
use App\Models\HubConnection;
use App\Models\HubExchange;
use Illuminate\Support\Facades\Log;

/**
 * Send one minute report and do what the Hub's answer asks: the next interval, what to add to the next report, a
 * history window to upload, a secret rotation. A missed report is never queued: the next one replaces it and the
 * backfill fills the charts.
 */
final class HubReporter
{
    public const MIN_INTERVAL = 60;

    public const MAX_INTERVAL = 300;

    private const DEFAULT_RETRY_AFTER = 60;

    public function __construct(private readonly ?HubClient $client = null) {}

    /** @return ?HubResponse null when the Hub could not be reached */
    public function send(HubConnection $connection): ?HubResponse
    {
        $report = HubReport::build($connection);
        $bytes = strlen((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        $startedAt = microtime(true);
        $connection->forceFill(['last_report_at' => now()])->save();

        try {
            $response = $this->client($connection)->report($connection, $report);
        } catch (HubException $e) {
            HubExchange::record(HubExchange::REPORT, 0, $startedAt, $bytes, $e->getMessage());

            return null;
        }
        HubExchange::record(HubExchange::REPORT, $response->status, $startedAt, $bytes, $this->detail($response));
        $this->answer($connection, $response);

        return $response;
    }

    private function answer(HubConnection $connection, HubResponse $response): void
    {
        match (true) {
            $response->status === 200 => $this->accepted($connection, $response),
            $response->status === 409 && $response->code() === HubResponse::UPGRADE_REQUIRED => HubUpgrade::attempt($connection, $this->client($connection)),
            $response->connectionUnknown() => $this->forgotten(),
            $response->status === 401 => Log::warning('PanelAlpha Connect rejected the report: clock or signature. Check this server\'s clock.'),
            $response->status === 413, $response->status === 422 => Log::warning('PanelAlpha Connect refused the report: '.$this->detail($response)),
            $response->status === 429 => $connection->forceFill(['report_not_before' => now()->addSeconds($response->retryAfter ?? self::DEFAULT_RETRY_AFTER)])->save(),
            default => null,
        };
    }

    private function accepted(HubConnection $connection, HubResponse $response): void
    {
        $data = is_array($response->json['data'] ?? null) ? $response->json['data'] : [];
        $interval = is_numeric($data['next_report_seconds'] ?? null) ? (int) $data['next_report_seconds'] : self::MIN_INTERVAL;
        $want = array_values(array_filter(is_array($data['want'] ?? null) ? $data['want'] : [], 'is_string'));

        $connection->forceFill([
            'report_interval_seconds' => max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval)),
            'report_want' => $want,
            'report_not_before' => null,
            'rotate_secret' => ($data['rotate_secret'] ?? false) === true,
        ])->save();

        $window = HubBackfillWindow::from($data['backfill'] ?? null);
        if ($window !== null) {
            SendHubBackfill::dispatch($window->toArray());
        }
    }

    private function forgotten(): void
    {
        Log::warning('PanelAlpha Connect no longer knows this engine\'s connection; it is removed here and reporting stops.');
        HubLifecycle::forget('hub_unknown');
    }

    private function detail(HubResponse $response): ?string
    {
        if ($response->ok()) {
            return null;
        }
        $fields = $response->fieldErrors();

        return $response->message().($fields === [] ? '' : ' | '.implode('; ', $fields));
    }

    private function client(HubConnection $connection): HubClient
    {
        return $this->client ?? new HubClient($connection->hub_url);
    }
}
