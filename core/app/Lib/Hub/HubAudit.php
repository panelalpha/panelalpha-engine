<?php

namespace App\Lib\Hub;

use Illuminate\Support\Facades\Log;

/**
 * One log line per `hub.connected`, `hub.disconnected`, `hub.token_rotated` or `hub.sso`. Callers pass ids
 * and names only, never a token, a secret, a device code or a login URL.
 */
final class HubAudit
{
    /** @param array<string, scalar|null> $context */
    public static function log(string $event, array $context = []): void
    {
        try {
            Log::info($event, $context);
        } catch (\Throwable) {
        }
    }
}
