<?php

namespace App\Lib\Hub;

use App\Models\HubAccessLog;
use App\Models\HubConnection;

/**
 * What PanelAlpha Connect's calls leave on its connection: when one last got
 * through, and the address they come from.
 */
final class HubConnectionCalls
{
    /** One call made with the connection's token, as it was answered. */
    public static function note(?string $connectionId, int $status, ?string $ip): void
    {
        if ($connectionId === null) {
            return;
        }
        if ($status < 400) {
            HubConnection::query()->where('connection_id', $connectionId)->update(['last_hub_call_at' => now()]);
        }
        if (! in_array($status, HubCallerAddress::REFUSED, true) && $ip !== null) {
            HubCallerAddress::seen($connectionId, $ip);
        }
    }

    /**
     * The Hub calls with the new token before `complete` returns, when there is
     * no connection yet to stamp: its last answered call and the address it came from.
     */
    public static function stampVerificationCalls(HubConnection $connection, int $tokenId): void
    {
        $calls = HubAccessLog::query()->where('token_id', $tokenId);
        $connection->forceFill([
            'last_hub_call_at' => (clone $calls)->where('status', '<', 400)->max('at'),
            'caller_ip' => (clone $calls)->whereNotIn('status', HubCallerAddress::REFUSED)->whereNotNull('source_ip')->orderByDesc('id')->value('source_ip'),
        ])->save();
    }
}
