<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\Models\PersonalAccessToken;

/** Forgetting a Hub connection: what the API and the CLI both need. */
final class HubLifecycle
{
    /**
     * Revoke every Hub token and delete every stored connection. Takes effect
     * at once and needs nothing from the Hub.
     */
    public static function forget(string $actor): int
    {
        $revoked = HubTokens::revokeAll();
        $connections = HubConnection::query()->get();

        foreach ($connections as $connection) {
            self::audit($connection, $actor, $revoked);
            $connection->delete();
        }
        HubCallerAddress::sync();

        return $revoked;
    }

    /**
     * The Hub revoked its own token. Only the connection that token names is
     * dropped: on `hub:connect --replace` the Hub revokes the old token while
     * the new connection is being completed.
     */
    public static function tokenRevoked(PersonalAccessToken $token): void
    {
        $id = HubTokens::connectionIdOf($token);
        if ($id === null) {
            return;
        }

        $connections = HubConnection::query()->where('connection_id', $id)->get();
        foreach ($connections as $connection) {
            self::audit($connection, 'hub', 1);
            $connection->delete();
        }
        if ($connections->isNotEmpty()) {
            HubCallerAddress::syncAfterResponse();
        }
    }

    private static function audit(HubConnection $connection, string $actor, int $revoked): void
    {
        HubAudit::log('hub.disconnected', [
            'connection_id' => $connection->connection_id,
            'account_id' => $connection->account_id,
            'actor' => $actor,
            'tokens_revoked' => $revoked,
        ]);
    }
}
