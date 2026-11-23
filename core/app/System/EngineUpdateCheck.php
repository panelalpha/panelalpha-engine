<?php

namespace App\System;

use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use Illuminate\Support\Facades\Cache;

/**
 * Whether a newer engine release exists: the running version against the newest engine release the PanelAlpha Hub
 * publishes. The Hub's answer is kept for an hour; the running version is read on every check, so an update that
 * finished needs no invalidation.
 */
final class EngineUpdateCheck
{
    public const STATUSES = ['update_available', 'up_to_date', 'older_available', 'unknown'];

    private const CACHE_KEY = 'engine-update-check';

    private const CACHE_SECONDS = 3600;

    /**
     * @return array{
     *   running: array{version: ?string},
     *   available: ?array{version: string, published_at: ?string, url: ?string, notes: ?string},
     *   status: string,
     *   update_available: bool,
     *   reason: ?string,
     *   checked_at: int,
     *   cached: bool
     * }
     */
    public function check(bool $refresh = false): array
    {
        $cached = $refresh ? null : $this->cached();
        $release = $cached ?? $this->ask();
        if ($cached === null && $release['available'] !== null) {
            Cache::put(self::CACHE_KEY, $release, self::CACHE_SECONDS);
        }

        $running = self::version(config('system.version'));
        $status = self::status($running, $release['available']['version'] ?? null);

        return [
            'running' => ['version' => $running],
            'available' => $release['available'],
            'status' => $status,
            'update_available' => $status === 'update_available',
            'reason' => $release['reason'] ?? ($running === null ? 'The running engine declares no version.' : null),
            'checked_at' => $release['checked_at'],
            'cached' => $cached !== null,
        ];
    }

    /**
     * @return ?array{
     *   available: array{version: string, published_at: ?string, url: ?string, notes: ?string},
     *   reason: null,
     *   checked_at: int
     * }
     */
    private function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! is_array($cached) || ! is_int($cached['checked_at'] ?? null) || ! is_array($cached['available'] ?? null)) {
            return null;
        }

        /** @var array{available: array{version: string, published_at: ?string, url: ?string, notes: ?string}, reason: null, checked_at: int} $cached */
        return $cached;
    }

    /**
     * @return array{
     *   available: ?array{version: string, published_at: ?string, url: ?string, notes: ?string},
     *   reason: ?string,
     *   checked_at: int
     * }
     */
    private function ask(): array
    {
        try {
            $response = (new HubClient)->releases();
        } catch (HubException $e) {
            return self::unknown($e->getMessage());
        }

        if (! $response->ok()) {
            return self::unknown("The PanelAlpha Hub answered the release list with {$response->message()}.");
        }

        $latest = $response->json['data']['engine']['latest'] ?? null;
        $version = is_array($latest) ? self::version($latest['version'] ?? null) : null;
        if ($version === null) {
            return self::unknown("The PanelAlpha Hub's release list names no engine release.");
        }

        return [
            'available' => [
                'version' => $version,
                'published_at' => self::text($latest, 'publishedAt'),
                'url' => self::text($latest, 'url'),
                'notes' => self::text($latest, 'notes'),
            ],
            'reason' => null,
            'checked_at' => time(),
        ];
    }

    /** @return array{available: null, reason: string, checked_at: int} */
    private static function unknown(string $reason): array
    {
        return ['available' => null, 'reason' => $reason, 'checked_at' => time()];
    }

    private static function status(?string $running, ?string $available): string
    {
        if ($running === null || $available === null) {
            return 'unknown';
        }

        return match (version_compare($available, $running)) {
            1 => 'update_available',
            0 => 'up_to_date',
            default => 'older_available',
        };
    }

    private static function version(mixed $value): ?string
    {
        $version = is_string($value) ? ltrim(trim($value), 'vV') : '';

        return $version === '' ? null : $version;
    }

    /** @param  array<mixed>  $from */
    private static function text(array $from, string $key): ?string
    {
        $value = $from[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
