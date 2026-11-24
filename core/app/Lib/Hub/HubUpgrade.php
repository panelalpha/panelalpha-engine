<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\Models\HubExchange;

/**
 * A connection made before push existed switches itself to push: same connection, account and grants, and the Hub
 * token it held stops working. Tried after every update and then daily until the Hub agrees.
 */
final class HubUpgrade
{
    private const RETRY_HOURS = 24;

    public static function isDue(HubConnection $connection): bool
    {
        return ! $connection->isPush()
            && ($connection->upgrade_attempted_at === null || $connection->upgrade_attempted_at->lt(now()->subHours(self::RETRY_HOURS)));
    }

    /** @return bool whether the connection is push now */
    public static function attempt(HubConnection $connection, ?HubClient $client = null): bool
    {
        $connection->forceFill(['upgrade_attempted_at' => now()])->save();
        $startedAt = microtime(true);
        try {
            $response = ($client ?? new HubClient($connection->hub_url))->upgrade($connection);
        } catch (HubException $e) {
            HubExchange::record(HubExchange::UPGRADE, 0, $startedAt, 0, $e->getMessage());

            return false;
        }
        HubExchange::record(HubExchange::UPGRADE, $response->status, $startedAt, 0, $response->ok() ? null : $response->message());
        if ($response->status !== 204) {
            return $connection->isPush();
        }

        $revoked = HubTokens::revokeAll();
        $connection->forceFill(['protocol' => HubConnection::PROTOCOL_PUSH, 'token_id' => null, 'last_report_at' => null])->save();
        HubCallerAddress::sync();
        HubAudit::log('hub.upgraded', ['connection_id' => $connection->connection_id, 'tokens_revoked' => $revoked]);

        return true;
    }
}
