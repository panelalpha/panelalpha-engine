<?php

namespace App\Lib\Domains;

/**
 * Which name a new project should get, best rung first: requested, sites_base_domain,
 * panelalpha_online, panelalpha_direct, local. Pure: decided from a settings snapshot alone.
 */
final class DomainPlan
{
    public const SOURCE_REQUESTED = 'requested';

    public const SOURCE_SITES_BASE_DOMAIN = 'sites_base_domain';

    public const SOURCE_PANELALPHA_ONLINE = 'panelalpha_online';

    public const SOURCE_PANELALPHA_DIRECT = 'panelalpha_direct';

    public const SOURCE_LOCAL = 'local';

    /** The tunnel a caller can ask for at create time. */
    public const TUNNEL_PANELALPHA = 'panelalpha';

    public const TUNNEL_NONE = 'none';

    public const TUNNELS = [self::TUNNEL_PANELALPHA, self::TUNNEL_NONE];

    /** The Engine setting deciding the tunnel a create that named none gets. */
    public const SETTING_DEFAULT_TUNNEL = 'default_tunnel';

    public const PANELALPHA_ONLINE = 'panelalpha.online';

    public const PANELALPHA_DIRECT = 'panelalpha.direct';

    /**
     * The rungs to try, best first; an explicit `panelalpha` tunnel outranks a configured base domain.
     *
     * @param  array{sites_base_domain?: ?string, cert_domain?: ?string, default_ipv4?: ?string, default_tunnel?: ?string}  $settings
     * @return list<array{domain: string, source: string, label: ?string}>
     */
    public static function candidates(
        string $username,
        ?string $requested,
        ?string $tunnel,
        array $settings
    ): array {
        $requested = self::normalise($requested);

        if ($requested !== null) {
            return [self::rung($requested, self::SOURCE_REQUESTED, self::onlineLabel($requested))];
        }

        return array_map(
            static fn (array $base): array => self::candidateOn($base, $username),
            self::bases($tunnel, $settings)
        );
    }

    /**
     * The domains a project that names none of its own is created under, best first: the first is the default.
     * The online rung needs only a public address: the proxy takes unlicensed calls and refuses with a reason.
     *
     * @param  array{sites_base_domain?: ?string, cert_domain?: ?string, default_ipv4?: ?string, default_tunnel?: ?string}  $settings
     * @return list<array{base: string, source: string, suffixed: bool}>
     */
    public static function bases(?string $tunnel, array $settings): array
    {
        $tunnel = self::resolveTunnel($tunnel, $settings);
        $configured = self::normalise($settings['sites_base_domain'] ?? null);
        $bases = [];

        if ($configured !== null && $tunnel !== self::TUNNEL_PANELALPHA) {
            $bases[] = self::base($configured, self::SOURCE_SITES_BASE_DOMAIN);
        }

        if ($tunnel !== self::TUNNEL_NONE && self::publicIpv4($settings) !== null) {
            $bases[] = self::base(self::PANELALPHA_ONLINE, self::SOURCE_PANELALPHA_ONLINE, suffixed: true);
        }

        $direct = self::directBase($settings);
        if ($direct !== null) {
            $bases[] = self::base($direct, self::SOURCE_PANELALPHA_DIRECT);
        }

        if ($configured !== null && $tunnel === self::TUNNEL_PANELALPHA) {
            $bases[] = self::base($configured, self::SOURCE_SITES_BASE_DOMAIN);
        }

        $bases[] = self::base('local', self::SOURCE_LOCAL);

        return $bases;
    }

    /** @return array{base: string, source: string, suffixed: bool} */
    private static function base(string $base, string $source, bool $suffixed = false): array
    {
        return [
            'base' => $base,
            'source' => $source,
            'suffixed' => $suffixed,
        ];
    }

    /**
     * Suffixed from the first attempt: the label namespace is fleet-wide, so a bare word is likely taken.
     *
     * @param  array{base: string, source: string, suffixed: bool}  $base
     * @return array{domain: string, source: string, label: ?string}
     */
    private static function candidateOn(array $base, string $username): array
    {
        if (! $base['suffixed']) {
            return self::rung($username.'.'.$base['base'], $base['source'], null);
        }

        $label = self::suffixed($username);

        return self::rung($label.'.'.$base['base'], $base['source'], $label);
    }

    /** @return array{domain: string, source: string, label: ?string} */
    private static function rung(string $domain, string $source, ?string $label): array
    {
        return [
            'domain' => $domain,
            'source' => $source,
            'label' => $label,
        ];
    }

    /**
     * The caller's tunnel, otherwise `none` only when the setting says so.
     *
     * @param  array{default_tunnel?: ?string}  $settings
     */
    public static function resolveTunnel(?string $tunnel, array $settings): ?string
    {
        if ($tunnel !== null) {
            return $tunnel;
        }

        return ($settings[self::SETTING_DEFAULT_TUNNEL] ?? null) === self::TUNNEL_NONE
            ? self::TUNNEL_NONE
            : null;
    }

    /**
     * Why no rung that answers from the open internet could be offered, or null when one was.
     * Kept short: it rides inside the warning sentence.
     *
     * @param  array{default_ipv4?: ?string}  $settings
     */
    public static function noPublicNameReason(array $settings): ?string
    {
        if (self::publicIpv4($settings) !== null) {
            return null;
        }

        if (self::ipv4($settings) !== null) {
            return 'no public IPv4: the host\'s address is private, so '.self::PANELALPHA_ONLINE
                .' could not serve it';
        }

        return 'no public IPv4: the host has no IPv4 address on record, so neither '
            .self::PANELALPHA_ONLINE.' nor '.self::PANELALPHA_DIRECT
            .' could give it a name at all';
    }

    /**
     * Whether a `www.` alias would answer: the WithoutDNS proxy serves only the exact label it
     * registered, so a name under panelalpha.online gets none.
     */
    public static function wwwAliasWouldAnswer(string $domain): bool
    {
        return self::onlineLabel($domain) === null;
    }

    /**
     * `<cert_domain>`, else the dashed-address zone (any address, private included), else nothing.
     *
     * @param  array{cert_domain?: ?string, default_ipv4?: ?string}  $settings
     */
    private static function directBase(array $settings): ?string
    {
        $certDomain = self::normalise($settings['cert_domain'] ?? null);
        if ($certDomain !== null) {
            return $certDomain;
        }

        $ipv4 = self::ipv4($settings);
        if ($ipv4 === null) {
            return null;
        }

        return str_replace('.', '-', $ipv4).'.'.self::PANELALPHA_DIRECT;
    }

    /** The single label of a `*.panelalpha.online` name, or null for anything else. */
    public static function onlineLabel(string $domain): ?string
    {
        if (! self::inOnlineZone($domain)) {
            return null;
        }

        $label = substr(self::bareName($domain), 0, -strlen('.'.self::PANELALPHA_ONLINE));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) === 1 ? $label : null;
    }

    /** A name at any depth under panelalpha.online, where the proxy holds the name and its certificate. */
    public static function inOnlineZone(string $domain): bool
    {
        return str_ends_with(self::bareName($domain), '.'.self::PANELALPHA_ONLINE);
    }

    private static function bareName(string $domain): string
    {
        return strtolower(trim($domain, " \t\n\r\0\x0B."));
    }

    /** A label to try after one was taken: random, since the panelalpha.online namespace is fleet-wide. */
    public static function suffixed(string $label): string
    {
        $stem = substr($label, 0, 40);

        return $stem.'-'.bin2hex(random_bytes(2));
    }

    /**
     * Public ranges only: the WithoutDNS proxy cannot forward to an address it cannot reach.
     *
     * @param  array{default_ipv4?: ?string}  $settings
     */
    private static function publicIpv4(array $settings): ?string
    {
        $ipv4 = trim((string) ($settings['default_ipv4'] ?? ''));

        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if ($ipv4 === '' || filter_var($ipv4, FILTER_VALIDATE_IP, $flags) === false) {
            return null;
        }

        return $ipv4;
    }

    /**
     * Whether the address this engine records is one the internet can reach.
     *
     * @param  array{default_ipv4?: ?string}  $settings
     */
    public static function hasPublicIpv4(array $settings): bool
    {
        return self::publicIpv4($settings) !== null;
    }

    /** Any syntactically valid IPv4 on record, private ranges included. */
    private static function ipv4(array $settings): ?string
    {
        $ipv4 = trim((string) ($settings['default_ipv4'] ?? ''));
        if ($ipv4 === '' || filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $ipv4;
    }

    private static function normalise(?string $value): ?string
    {
        $value = strtolower(trim((string) $value, " \t\n\r\0\x0B."));

        return $value === '' ? null : $value;
    }
}
