<?php

namespace App\Lib\Hub;

use App\Lib\Host\ProjectStats;
use App\Lib\Host\ServerMetricsHistory;
use App\Models\HubConnection;
use App\Models\HubExchange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Upload the engine's own history for a window the Hub asked for, so its charts have no hole from before the
 * connection or from an outage. The window is cut by time and by project into uploads under the Hub's size limit; the
 * last one says it is done, and its answer may name the next window.
 */
final class HubBackfill
{
    public const UPLOAD_BYTES = 900_000;

    private const SLICE_POINTS = 360;

    private const ATTEMPTS = 3;

    private const HOST_METRICS = ['cpu_percent', 'ram_percent', 'disk_percent', 'net_in_bps', 'net_out_bps', 'disk_read_bps', 'disk_write_bps'];

    private const PERCENTS = ['cpu_percent', 'ram_percent', 'disk_percent'];

    public function __construct(private readonly ?HubClient $client = null) {}

    /** @return ?HubBackfillWindow the next window the Hub asked for */
    public function send(HubConnection $connection, HubBackfillWindow $window): ?HubBackfillWindow
    {
        $uploads = $this->uploads($window);
        $kept = 0;
        $next = null;
        foreach ($uploads as $upload) {
            $response = $this->upload($connection, $upload);
            if ($response === null || ! $response->ok()) {
                $closed = $response?->status === 409 && $response->code() === HubResponse::NO_OPEN_WINDOW;
                $this->remember($connection, $window, $kept, $closed ? 'closed by the Hub' : 'gave up');

                return null;
            }
            $kept += (int) ($response->json['data']['kept'] ?? 0);
            $next = HubBackfillWindow::from($response->json['data']['backfill'] ?? null);
        }
        $this->remember($connection, $window, $kept, 'done');

        return $next;
    }

    /**
     * Every upload for the window, the last one `done`. A window with no history still gets one, so the Hub closes it.
     *
     * @return list<array<string, mixed>>
     */
    public function uploads(HubBackfillWindow $window): array
    {
        $usernames = HubReport::projectUsernames();
        $start = $window->start();
        $end = $window->end();
        $sliceSeconds = self::SLICE_POINTS * $window->step;

        $uploads = [];
        for ($from = $start->copy(); $from->lt($end); $from->addSeconds($sliceSeconds)) {
            $to = $from->copy()->addSeconds($sliceSeconds)->min($end);
            array_push($uploads, ...$this->slice($window, $usernames, $from, $to));
        }
        if ($uploads === []) {
            $uploads[] = $this->body($window, [], []);
        }
        $uploads[array_key_last($uploads)]['done'] = true;

        return $uploads;
    }

    /**
     * @param  list<string>  $usernames
     * @return list<array<string, mixed>>
     */
    private function slice(HubBackfillWindow $window, array $usernames, Carbon $from, Carbon $to): array
    {
        $host = array_map(self::hostPoint(...), ServerMetricsHistory::between($from, $to, $window->step));
        $totals = $usernames === [] ? [] : ProjectStats::totalsBetween($usernames, $from, $to, $window->step);
        $containers = $usernames === [] ? [] : ProjectStats::containersBetween($usernames, $from, $to, $window->step);

        $parts = [];
        foreach (array_unique([...array_keys($totals), ...array_keys($containers)]) as $username) {
            array_push($parts, ...$this->parts((string) $username, $totals[$username] ?? [], $containers[$username] ?? []));
        }
        if ($host === [] && $parts === []) {
            return [];
        }

        return $this->pack($window, $host, $parts);
    }

    /**
     * One project's slice, cut into pieces that each fit an upload: its totals with as many containers as fit, then the
     * rest of its containers.
     *
     * @param  list<array<string, mixed>>  $total
     * @param  array<string, list<array<string, mixed>>>  $containers
     * @return list<array{username: string, data: array<string, mixed>, bytes: int}>
     */
    private function parts(string $username, array $total, array $containers): array
    {
        $parts = [];
        $data = ['total' => array_map(self::projectPoint(...), $total), 'containers' => []];
        foreach ($containers as $key => $points) {
            $candidate = $data;
            $candidate['containers'][$key] = array_map(self::projectPoint(...), $points);
            if ($data['containers'] !== [] && self::bytes($candidate) > self::UPLOAD_BYTES) {
                $parts[] = self::part($username, $data);
                $data = ['total' => [], 'containers' => [$key => $candidate['containers'][$key]]];

                continue;
            }
            $data = $candidate;
        }
        $parts[] = self::part($username, $data);

        return $parts;
    }

    /**
     * @param  list<array<string, mixed>>  $host
     * @param  list<array{username: string, data: array<string, mixed>, bytes: int}>  $parts
     * @return list<array<string, mixed>>
     */
    private function pack(HubBackfillWindow $window, array $host, array $parts): array
    {
        $uploads = [];
        $projects = [];
        $bytes = self::bytes($host);
        $withHost = $host;
        foreach ($parts as $part) {
            if ($projects !== [] && $bytes + $part['bytes'] > self::UPLOAD_BYTES) {
                $uploads[] = $this->body($window, $withHost, $projects);
                [$projects, $bytes, $withHost] = [[], 0, null];
            }
            $projects[$part['username']] = $part['data'];
            $bytes += $part['bytes'];
        }
        $uploads[] = $this->body($window, $withHost, $projects);

        return $uploads;
    }

    /**
     * @param  ?list<array<string, mixed>>  $host  null leaves it out of the upload
     * @param  array<string, array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    private function body(HubBackfillWindow $window, ?array $host, array $projects): array
    {
        $body = ['window' => $window->toArray()];
        if ($host !== null) {
            $body['host'] = $host;
        }
        $body['projects'] = (object) array_map(
            fn (array $data): array => ['total' => $data['total'], 'containers' => (object) $data['containers']],
            $projects
        );
        $body['done'] = false;

        return $body;
    }

    /** @param  array<string, mixed>  $upload */
    private function upload(HubConnection $connection, array $upload): ?HubResponse
    {
        $bytes = self::bytes($upload);
        $client = $this->client ?? new HubClient($connection->hub_url);
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $startedAt = microtime(true);
            try {
                $response = $client->metrics($connection, $upload);
                HubExchange::record(HubExchange::BACKFILL, $response->status, $startedAt, $bytes, $response->ok() ? null : $response->message());
                if ($response->ok() || $response->status < 500 && $response->status !== 429) {
                    return $response;
                }
            } catch (HubException $e) {
                HubExchange::record(HubExchange::BACKFILL, 0, $startedAt, $bytes, $e->getMessage());
            }
            if ($attempt < self::ATTEMPTS) {
                Sleep::for(2 ** $attempt)->seconds();
            }
        }

        return null;
    }

    private function remember(HubConnection $connection, HubBackfillWindow $window, int $kept, string $result): void
    {
        $connection->forceFill(['last_backfill' => $window->toArray() + [
            'kept' => $kept,
            'result' => $result,
            'at' => now()->toIso8601ZuluString(),
        ]])->save();
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private static function hostPoint(array $point): array
    {
        $out = ['period' => $point['period']];
        foreach (self::HOST_METRICS as $metric) {
            $value = $point[$metric] ?? null;
            $out[$metric] = in_array($metric, self::PERCENTS, true) ? HubValues::percent($value) : HubValues::count($value);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private static function projectPoint(array $point): array
    {
        return [
            'period' => $point['period'],
            'cpu_percent' => HubValues::share($point['cpu_percent'] ?? null),
            'memory_bytes' => HubValues::count($point['memory_bytes'] ?? null),
            'memory_limit_bytes' => HubValues::count($point['memory_limit_bytes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{username: string, data: array<string, mixed>, bytes: int}
     */
    private static function part(string $username, array $data): array
    {
        return ['username' => $username, 'data' => $data, 'bytes' => self::bytes([$username => $data])];
    }

    private static function bytes(mixed $value): int
    {
        return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }
}
