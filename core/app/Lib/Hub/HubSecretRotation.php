<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\Models\HubExchange;

/**
 * Take the Hub's next secret and sign with it from now on. The one it replaces checks login tickets for a few minutes
 * more, since the Hub signed those before it saw the new one. A lost answer changes nothing: the old secret keeps
 * working, and the Hub hands out the same new one when it asks again.
 */
final class HubSecretRotation
{
    public const PREVIOUS_SECRET_MINUTES = 10;

    public static function rotate(HubConnection $connection, ?HubClient $client = null): bool
    {
        $connection->forceFill(['rotate_secret' => false])->save();
        $startedAt = microtime(true);
        try {
            $response = ($client ?? new HubClient($connection->hub_url))->nextSecret($connection);
        } catch (HubException $e) {
            HubExchange::record(HubExchange::ROTATE, 0, $startedAt, 0, $e->getMessage());

            return false;
        }
        $secret = $response->json['data']['hub_secret'] ?? null;
        $valid = $response->status === 200 && is_string($secret) && preg_match('/\A[0-9a-f]{64}\z/i', $secret) === 1;
        HubExchange::record(HubExchange::ROTATE, $response->status, $startedAt, 0, $valid ? null : $response->message());
        if (! $valid || $secret === $connection->hub_secret) {
            return false;
        }

        $connection->forceFill([
            'hub_secret_previous' => $connection->hub_secret,
            'hub_secret_previous_until' => now()->addMinutes(self::PREVIOUS_SECRET_MINUTES),
            'hub_secret' => $secret,
        ])->save();
        HubAudit::log('hub.secret_rotated', ['connection_id' => $connection->connection_id]);

        return true;
    }
}
