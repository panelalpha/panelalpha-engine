<?php

namespace App\System\Services\Webserver;

use App\Models\ProxyRule;

/**
 * Two enabled HTTP rules on one server name and listen port: the vhost renders
 * one entry per port, so the row order decides which upstream serves the
 * domain and the other rule disappears without a message.
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
}
