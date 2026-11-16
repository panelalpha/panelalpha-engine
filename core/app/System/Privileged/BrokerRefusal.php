<?php

namespace App\System\Privileged;

/**
 * What the broker said about a request it refused, for a sentence of the caller's own.
 *
 * @internal
 */
final class BrokerRefusal
{
    public static function reasonIn(string $said): string
    {
        return Root::refusalReason($said);
    }
}
