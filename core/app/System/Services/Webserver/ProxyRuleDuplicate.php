<?php

namespace App\System\Services\Webserver;

use App\Models\ProxyRule;

/**
 * Two enabled rules on one server name and port (HTTP), or one address and port (tcp/udp): the config renders one
 * entry, so row order picks the upstream and the other rule silently disappears.
 */
final class ProxyRuleDuplicate
{
    /** What the REST controller and the CLI refuse a duplicate with. */
    public const MESSAGE = 'A rule with the same transport, port, and server_name already exists';

    /** Why a second enabled HTTP rule may not serve this name and port, or null when it may. */
    public static function refusal(?string $serverName, int $listenPort, ?int $ignoreId = null): ?string
    {
        return ProxyRule::conflictingHttpRule($serverName, $listenPort, $ignoreId) === null ? null : self::MESSAGE;
    }

    /** Why a second enabled rule of this transport may not take this listener, or null when it may. */
    public static function ruleRefusal(string $transport, string $listenIp, int $listenPort, ?string $serverName, ?int $ignoreId = null): ?string
    {
        if ($transport === 'http') {
            return self::refusal($serverName, $listenPort, $ignoreId);
        }

        return ProxyRule::conflictingStreamRule($transport, $listenIp, $listenPort, $ignoreId) === null ? null : self::MESSAGE;
    }
}
