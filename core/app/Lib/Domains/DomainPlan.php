<?php

namespace App\Lib\Domains;

/**
 * Which name a new project should get, in order of preference.
 *
 * A project is only worth creating if someone can open it, and on an engine
 * with no DNS of its own that used to be nobody's job: a caller who did not
 * name a domain got `<username>.local`, which resolves nowhere, and the
 * knowledge of what to ask for instead lived in prose outside the API. This
 * is that knowledge, as a list.
 *
 * The order is a preference, not a guess -- each rung is worse than the one
 * above it in a way that can be stated:
 *
 *   requested          the caller's own name. Never overridden.
 *   sites_base_domain  the operator configured what projects are called here.
 *   panelalpha_online  a wildcard zone in front of the WithoutDNS proxy: any
 *                      free label resolves worldwide with a trusted
 *                      certificate. Costs a remote allocation, so it is the
 *                      only rung that can fail after being chosen.
 *   panelalpha_direct  <name>.<cert_domain>, or the dashed-address default.
 *                      Resolves to this host for free; served the certificate
 *                      the engine signs itself.
 *   local              nothing above was possible. Resolves nowhere, and says so.
 *
 * No Laravel dependencies: the ladder is decided from a settings snapshot and
 * nothing else, so it can be tested without a database or a network. Walking
 * it -- checking a name is free, spending the remote allocation -- is
 * {@see DomainAllocator}'s.
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

    public const PANELALPHA_ONLINE = 'panelalpha.online';
    public const PANELALPHA_DIRECT = 'panelalpha.direct';

    /**
     * The rungs to try, best first.
     *
     * @param string $username the project name, which is also the label a
     *        generated domain is built from
     * @param ?string $requested the caller's `domain`, if it gave one
     * @param ?string $tunnel the caller's `tunnel`, if it gave one. Asking
     *        for `panelalpha` explicitly outranks a configured base domain --
     *        an explicit request beats a default, even the operator's.
     * @param array{sites_base_domain?: ?string, cert_domain?: ?string, default_ipv4?: ?string} $settings
     * @return list<array{domain: string, source: string, label: ?string}>
     *         `label` is set only on the rung that has to be allocated
     *         remotely, and is the single label to ask the proxy for.
     */
    public static function candidates(
        string $username,
        ?string $requested,
        ?string $tunnel,
        array $settings
    ): array {
        $requested = self::normalise($requested);

        if ($requested !== null) {
            return [[
                'domain' => $requested,
                'source' => self::SOURCE_REQUESTED,
                'label' => self::onlineLabel($requested),
            ]];
        }

        $candidates = [];
        $wantsOnline = $tunnel !== self::TUNNEL_NONE;
        $base = self::normalise($settings['sites_base_domain'] ?? null);

        if ($base !== null && $tunnel !== self::TUNNEL_PANELALPHA) {
            $candidates[] = [
                'domain' => $username . '.' . $base,
                'source' => self::SOURCE_SITES_BASE_DOMAIN,
                'label' => null,
            ];
        }

        // Offered on the strength of a public address alone. The proxy takes
        // an unlicensed call -- measured against the staging endpoint, which
        // answered 200 with no token -- so gating this on a license key would
        // deny the best name to exactly the hosts with no other one: a fresh
        // install, unlicensed, with no zone of its own. An engine the proxy
        // will not serve finds out by asking, and drops the rung with a
        // reason, which is the same answer a day later when the key expires.
        if ($wantsOnline && self::publicIpv4($settings) !== null) {
            // Suffixed from the first attempt, never `shop` bare. A
            // registration is scoped to the engine's own license key, so
            // asking again for a label this engine already holds overrides
            // its own record rather than colliding -- what conflicts is
            // another engine's claim on the same word, out of a namespace the
            // whole fleet draws on. A bare `shop` is one somebody has almost
            // certainly taken, so asking for it buys a refusal and a retry;
            // four random hex skip both and cost a caller nothing, since the
            // name is read off the response rather than typed. Deleting a
            // tunnel now releases the label at the proxy, so a name is no
            // longer held for ever -- but a name held for as long as the
            // project lives is still one nobody else can have.
            $label = self::suffixed($username);
            $candidates[] = [
                'domain' => $label . '.' . self::PANELALPHA_ONLINE,
                'source' => self::SOURCE_PANELALPHA_ONLINE,
                'label' => $label,
            ];
        }

        $direct = self::directDomain($username, $settings);
        if ($direct !== null) {
            $candidates[] = [
                'domain' => $direct,
                'source' => self::SOURCE_PANELALPHA_DIRECT,
                'label' => null,
            ];
        }

        if ($base !== null && $tunnel === self::TUNNEL_PANELALPHA) {
            // Asking for a tunnel put panelalpha.online first, but a configured
            // base domain is still a better last resort than `.local`.
            $candidates[] = [
                'domain' => $username . '.' . $base,
                'source' => self::SOURCE_SITES_BASE_DOMAIN,
                'label' => null,
            ];
        }

        $candidates[] = [
            'domain' => $username . '.local',
            'source' => self::SOURCE_LOCAL,
            'label' => null,
        ];

        return $candidates;
    }

    /**
     * Why no rung that answers from the open internet could be offered, or
     * null when one was.
     *
     * `fallback_reason` used to record only a rung that was *tried and
     * refused*, so the one outcome most in need of an explanation -- a
     * project on `.local`, which nobody can open -- carried none. A rung that
     * was never a candidate leaves no trace of itself unless something says
     * so, and this is what says so.
     *
     * @param array{default_ipv4?: ?string} $settings
     */
    public static function noPublicNameReason(array $settings): ?string
    {
        if (self::publicIpv4($settings) !== null) {
            return null;
        }

        // Short on purpose: this rides inside the warning sentence, which
        // already says what the name does answer.
        if (self::ipv4($settings) !== null) {
            return 'no public IPv4: the host\'s address is private, so ' . self::PANELALPHA_ONLINE
                . ' could not serve it';
        }

        return 'no public IPv4: the host has no IPv4 address on record, so neither '
            . self::PANELALPHA_ONLINE . ' nor ' . self::PANELALPHA_DIRECT
            . ' could give it a name at all';
    }

    /**
     * Whether a `www.` alias for this name would answer.
     *
     * The WithoutDNS proxy serves the exact label it registered and nothing
     * below it, while the zone's DNS resolves at any depth -- so
     * `www.<label>.panelalpha.online` points at the proxy, which has no
     * registration for it, and its certificate covers one label anyway.
     * Measured: TLS refused outright, and plain HTTP redirected into the
     * proxy's own default page. An alias that behaves like that is worse than
     * no alias, so a name under that zone does not get one.
     */
    public static function wwwAliasWouldAnswer(string $domain): bool
    {
        return self::onlineLabel($domain) === null;
    }

    /**
     * `<name>.<cert_domain>`, else the dashed-address default, else nothing.
     *
     * `cert_domain` is preferred because it is a name this engine already
     * holds a certificate for -- one covering the admin name today, and a
     * wildcard over every project tomorrow.
     *
     * @param array{cert_domain?: ?string, default_ipv4?: ?string} $settings
     */
    public static function directDomain(string $username, array $settings): ?string
    {
        $certDomain = self::normalise($settings['cert_domain'] ?? null);
        if ($certDomain !== null) {
            return $username . '.' . $certDomain;
        }

        // Any address, public or private. This rung is DNS and nothing else:
        // the zone resolves whatever address the label spells, at any depth,
        // so `shop.10-0-0-4.panelalpha.direct` answers 10.0.0.4 for every
        // resolver on that LAN. `.local` answers for nobody -- the engine
        // registers no mDNS -- so on a private host the dashed name is the
        // better of the two, and the only one a second VM can open.
        //
        // panelalpha.online is the rung that genuinely needs a public
        // address: the proxy has to reach the host from the internet.
        $ipv4 = self::ipv4($settings);
        if ($ipv4 === null) {
            return null;
        }

        return $username . '.' . str_replace('.', '-', $ipv4) . '.' . self::PANELALPHA_DIRECT;
    }

    /**
     * The single label of a `*.panelalpha.online` name, or null for anything
     * else. What tells a requested domain apart from a name that has to be
     * allocated before it answers.
     */
    public static function onlineLabel(string $domain): ?string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        $suffix = '.' . self::PANELALPHA_ONLINE;

        if (!str_ends_with($domain, $suffix)) {
            return null;
        }

        $label = substr($domain, 0, -strlen($suffix));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) === 1 ? $label : null;
    }

    /**
     * A label to try after one was taken. Short and random rather than
     * sequential: labels under panelalpha.online are one namespace for the
     * whole fleet, so `shop2` is a name someone else is about to want.
     */
    public static function suffixed(string $label): string
    {
        $stem = substr($label, 0, 40);

        return $stem . '-' . bin2hex(random_bytes(2));
    }

    /** @param array{default_ipv4?: ?string} $settings */
    private static function publicIpv4(array $settings): ?string
    {
        $ipv4 = trim((string) ($settings['default_ipv4'] ?? ''));

        // Public ranges only: the WithoutDNS proxy cannot forward to an
        // address it cannot reach, so panelalpha.online on a private host
        // would be pretence. What a private address can still have is the
        // panelalpha.direct name -- see directDomain(), which asks ipv4().
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if ($ipv4 === '' || filter_var($ipv4, FILTER_VALIDATE_IP, $flags) === false) {
            return null;
        }

        return $ipv4;
    }

    /**
     * Whether the address this engine records is one the internet can reach.
     *
     * @param array{default_ipv4?: ?string} $settings
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
