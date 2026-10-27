<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\Models\PersonalAccessToken;

/** Forgetting a Hub connection, and keeping it in step with its token: what the API and the CLI both need. */
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

    /** A rotated Hub token hands its connection to the token that replaced it. */
    public static function tokenRotated(PersonalAccessToken $old, int $newTokenId): void
    {
        $id = HubTokens::connectionIdOf($old);
        if ($id === null) {
            return;
        }

        HubConnection::query()->where('connection_id', $id)->update(['token_id' => $newTokenId]);
        HubAudit::log('hub.token_rotated', [
            'connection_id' => $id,
            'old_token_id' => $old->id,
            'new_token_id' => $newTokenId,
        ]);
    }

    /**
     * The Hub revoked its own token. Only a connection that token still holds
     * is dropped: on `hub:connect --replace` the Hub revokes the old token while
     * the new connection is being completed, and a token superseded by a
     * rotation no longer holds its connection.
     */
    public static function tokenRevoked(PersonalAccessToken $token): void
    {
        $id = HubTokens::connectionIdOf($token);
        if ($id === null) {
            return;
        }

        $connections = HubConnection::query()->where('connection_id', $id)->where('token_id', $token->id)->get();
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
