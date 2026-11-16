<?php

namespace App\System\Privileged;

/**
 * Re-asks a gateway-built broker request with `--host`. The broker allows that only for actions that declare it.
 *
 * @internal
 */
final class HostNamespaces
{
    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function of(array $command): array
    {
        return Root::inHostNamespaces($command);
    }
}
