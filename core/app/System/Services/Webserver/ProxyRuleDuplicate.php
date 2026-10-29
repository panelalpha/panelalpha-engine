<?php

namespace App\System\Services\Webserver;

use App\Models\ProxyRule;

/**
 * Two enabled HTTP rules on one server name and listen port: the vhost renders
 * one entry per port, so the row order decides which upstream serves the
 * domain and the other rule disappears without a message. Two enabled tcp or
 * udp rules on one address and port end the same way in stream.conf.
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
