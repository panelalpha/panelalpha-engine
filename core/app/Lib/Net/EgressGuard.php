<?php

namespace App\Lib\Net;

use App\Lib\Http\OutboundUrl;

/**
 * The engine's single answer to "may core fetch this URL?".
 *
 * Generalised from the address check behind file downloads and git clones
 * ({@see OutboundUrl}), it refuses a URL whose host resolves to loopback,
 * link-local (the cloud metadata service), the Docker and tenant networks, or
 * a private range unless the operator allows it
 * ({@see config('egress.allow_private')}, {@see config('egress.allow_hosts')}).
 * The checked address is returned so the caller pins the connection to it.
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
     * The problem a refused URL should be reported under, or null when it may
     * be fetched. The code is stable so a client can act on it.
     *
     * @param  list<string>  $schemes
     * @return ?array{field: string, code: string, message: string}
     */
    public static function problem(string $field, string $url, array $schemes = self::DEFAULT_SCHEMES): ?array
    {
        try {
            self::assertAllowed($url, $schemes);

            return null;
        } catch (EgressRefusedException $e) {
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
