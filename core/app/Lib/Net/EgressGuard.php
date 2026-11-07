<?php

namespace App\Lib\Net;

use App\Lib\Http\OutboundUrl;

/**
 * "May core fetch this URL?": refuses loopback, link-local (the cloud metadata service), Docker, tenant and, unless
 * egress.allow_private or egress.allow_hosts allows it, private addresses via {@see OutboundUrl}; returns the address to pin to.
 */
final class EgressGuard
{
    /** The schemes a fetch may use. */
    public const DEFAULT_SCHEMES = OutboundUrl::DEFAULT_SCHEMES;

    /**
     * @param  list<string>  $schemes
     *
     * @throws EgressRefusedException
     */
    public static function assertAllowed(string $url, array $schemes = self::DEFAULT_SCHEMES): ResolvedTarget
    {
        return OutboundUrl::assertAllowed($url, $schemes);
    }

    /**
     * @param  list<string>  $schemes
     */
    public static function isAllowed(string $url, array $schemes = self::DEFAULT_SCHEMES): bool
    {
        return OutboundUrl::isAllowed($url, $schemes);
    }

    /**
     * A `host:port:address` value pinning the connection to the checked
     * address, or null for a literal address.
     *
     * @param  list<string>  $schemes
     *
     * @throws EgressRefusedException
     */
    public static function pin(string $url, array $schemes = self::DEFAULT_SCHEMES): ?string
    {
        return OutboundUrl::pin($url, $schemes);
    }

    /** Whether a host name is on the operator's allow list. */
    public static function allowsHost(string $host): bool
    {
        return OutboundUrl::allowsHost($host);
    }

    /**
     * The problem (with a stable code) a refused URL is reported under, or null. A host that does not resolve
     * is refused too, since git and curl still read numeric forms such as `0x7f000001` as an address.
     *
     * @param  list<string>  $schemes
     * @return ?array{field: string, code: string, message: string}
     */
    public static function problem(string $field, string $url, array $schemes = self::DEFAULT_SCHEMES): ?array
    {
        try {
            self::assertAllowed($url, $schemes);

            return null;
        } catch (\InvalidArgumentException $e) {
            return ['field' => $field, 'code' => EgressRefusedException::CODE, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  ?bool  $allowPrivate  null restores the configured policy
     * @param  ?list<string>  $allowHosts  null restores the configured policy
     */
    public static function setPolicyForTests(?bool $allowPrivate, ?array $allowHosts): void
    {
        OutboundUrl::setPolicyForTests($allowPrivate, $allowHosts);
    }
}
