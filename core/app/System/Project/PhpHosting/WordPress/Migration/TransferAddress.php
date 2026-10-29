<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;
use App\Lib\Http\OutboundUrl;
use App\Models\Ipv4NatMap;
use App\Models\Setting;

/**
 * The engine's IPv4 a source site sends an import's transfer to, whatever the
 * destination name's DNS says: the public address the NAT map gives the default
 * IPv4, as DNS gets it. A source on the internet cannot reach a private one, so
 * such an import is refused before anything is installed on the source; with
 * EGRESS_ALLOW_PRIVATE the source may share the engine's private network.
 */
final class TransferAddress
{
    public const UNREACHABLE = 'engine_address_private';

    /** Null when the engine records none: the source then resolves the name itself. */
    public static function ipv4(): ?string
    {
        $ipv4 = trim((string) Setting::get('default_ipv4'));

        return filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : self::publicOf($ipv4);
    }

    private static function publicOf(string $ipv4): string
    {
        $public = Ipv4NatMap::isNatModeEnabled() ? (Ipv4NatMap::getLocalToPublicMap()[$ipv4] ?? null) : null;

        return is_string($public) && filter_var($public, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $public : $ipv4;
    }

    /** Why the source site cannot send the transfer to this engine, or null when it can. */
    public static function refusal(): ?string
    {
        $ipv4 = self::ipv4();
        if ($ipv4 === null || self::reachable($ipv4)) {
            return null;
        }

        return "This engine's IPv4 address {$ipv4} is not a public address, so the source site cannot send the "
            ."transfer to it. Set the engine's public IPv4 address as its default IPv4, or map the default IPv4 to it "
            .'in the IPv4 NAT map, before importing, or allow private addresses with EGRESS_ALLOW_PRIVATE when the '
            .'source site is on the same private network.';
    }

    /**
     * @throws ProblemException 409 when the source site cannot reach this engine
     */
    public static function assertReachable(): void
    {
        $refusal = self::refusal();
        if ($refusal !== null) {
            throw ProblemException::conflict('url', self::UNREACHABLE, $refusal);
        }
    }

    private static function reachable(string $ipv4): bool
    {
        $flags = OutboundUrl::allowsPrivate() ? FILTER_FLAG_NO_RES_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags) !== false;
    }
}
