<?php

namespace App\Console\Commands\Concerns;

use App\Lib\Project\PublicRouting\PublicRoutingGrants;

/** The `--ports` / `--host-domain` options of the public-routing commands, and how they print. */
final class PublicRoutingKinds
{
    private const LABELS = [
        PublicRoutingGrants::PORTS => 'public ports',
        PublicRoutingGrants::HOST_DOMAIN => "the server's own name",
    ];

    /** @return list<string> */
    public static function fromOptions(bool $ports, bool $hostDomain): array
    {
        return array_values(array_filter([
            $ports ? PublicRoutingGrants::PORTS : null,
            $hostDomain ? PublicRoutingGrants::HOST_DOMAIN : null,
        ]));
    }

    /** @param list<string> $kinds */
    public static function label(array $kinds): string
    {
        $labels = array_map(static fn (string $kind): string => self::LABELS[$kind] ?? $kind, $kinds);

        return $labels === [] ? 'nothing' : implode(' and ', $labels);
    }
}
