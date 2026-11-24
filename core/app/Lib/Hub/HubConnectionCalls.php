<?php

namespace App\Lib\Hub;

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
}
