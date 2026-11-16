<?php

namespace App\Lib\Domains;

use App\Lib\Findings\Finding;
use App\Lib\Ssl\CertificateStatus;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\Dind\AppHealth;

/**
 * Whether a visitor can open the URL the deploy reported: {@see AppHealth} judges only loopback.
 * Reads what the deploy recorded in `details.domain` and `details.ssl`; probes nothing.
 */
final class PublicUrl
{
    /** TLS the engine does not serve: the WithoutDNS proxy's certificate is what a visitor is shown. */
    public const TLS_AT_PROXY = 'proxy';

    public const UNRESOLVABLE = 'public_url_unresolvable';

    public const CERTIFICATE_REFUSED = 'certificate_refused';

    /** Certificate verdicts a browser refuses; `missing` and `unreadable` are not, on purpose. */
    private const REFUSED_BY_BROWSERS = [
        CertificateStatus::SELF_SIGNED,
        CertificateStatus::UNTRUSTED_ISSUER,
        CertificateStatus::EXPIRED,
        CertificateStatus::NOT_YET_VALID,
        CertificateStatus::DOMAIN_MISMATCH,
    ];

    /**
     * What is wrong with this project's public URL, if anything.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @return list<Finding>
     */
    public static function warnings(string $domain, array $details): array
    {
        return array_values(array_filter([
            self::unresolvableWarning($domain, $details),
            self::certificateWarning($domain, $details),
        ]));
    }

    /**
     * A fallback name that still resolves publicly: said in the deploy log without marking it partial.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @return list<string>
     */
    public static function notices(string $domain, array $details): array
    {
        $allocation = self::allocation($details);
        $reason = self::fallbackReason($allocation);

        if ($reason === null || ($allocation['publicly_resolvable'] ?? null) === false) {
            return [];
        }

        $source = is_string($allocation['source'] ?? null) ? " ({$allocation['source']})" : '';

        return ["Serving under {$domain}{$source}, a fallback name: the preferred public name was skipped: {$reason}."];
    }

    /**
     * A name that answers only on this host or network: strictly `false`, since null means DNS we do not control.
     *
     * @param  array<string, mixed>  $details
     */
    private static function unresolvableWarning(string $domain, array $details): ?Finding
    {
        $allocation = self::allocation($details);

        if (($allocation['publicly_resolvable'] ?? null) !== false) {
            return null;
        }

        $privateNetwork = ($allocation['source'] ?? null) === DomainPlan::SOURCE_PANELALPHA_DIRECT;
        $reason = self::fallbackReason($allocation);

        return new Finding(
            self::UNRESOLVABLE,
            self::unreachableSentence($domain, $privateNetwork, $reason),
            array_filter([
                'domain' => $domain,
                'resolves_on' => $privateNetwork ? 'private_network' : 'this_host',
                'fallback_reason' => $reason,
            ], static fn (?string $fact): bool => $fact !== null),
        );
    }

    /** A `.direct` name on a private address works from the next VM along, so "this host only" would be wrong for it. */
    private static function unreachableSentence(string $domain, bool $privateNetwork, ?string $reason): string
    {
        $where = $privateNetwork
            ? " resolves to this host's private address, so it answers on this network only."
            : ' resolves on this host only.';
        $skipped = $reason !== null ? " The public name was skipped: {$reason}." : '';

        return "The application is deployed but not reachable from the internet: {$domain}{$where}{$skipped}"
            .' Point a domain you control at this host, or redeploy once a public name can be allocated.';
    }

    /**
     * @param  array<string, mixed>  $allocation
     */
    private static function fallbackReason(array $allocation): ?string
    {
        $reason = $allocation['fallback_reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? rtrim(trim($reason), '.') : null;
    }

    /**
     * A certificate the engine serves and a browser will not accept.
     *
     * @param  array<string, mixed>  $details
     */
    private static function certificateWarning(string $domain, array $details): ?Finding
    {
        if ((self::allocation($details)['tls_terminated_at'] ?? null) === self::TLS_AT_PROXY) {
            return null;
        }

        $ssl = $details[AppCertificate::DETAIL] ?? null;
        $ssl = is_array($ssl) ? $ssl : [];

        $status = $ssl['status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::REFUSED_BY_BROWSERS, true)) {
            return null;
        }

        $name = is_string($ssl['domain'] ?? null) && $ssl['domain'] !== '' ? $ssl['domain'] : $domain;
        $issuer = $ssl['issuer'] ?? null;
        $issuer = is_string($issuer) && $issuer !== '' && $issuer !== 'Unknown' ? $issuer : null;

        return new Finding(
            self::CERTIFICATE_REFUSED,
            "https://{$name} is served a certificate browsers will refuse ({$status}"
                .($issuer !== null ? ", issued by {$issuer}" : '')
                .'). A trusted one can be requested once the name resolves to this host.',
            ['domain' => $name, 'certificate' => array_filter(['status' => $status, 'issuer' => $issuer])],
        );
    }

    /**
     * The `details.domain` block, or an empty one on a project created before the allocator recorded it.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function allocation(array $details): array
    {
        $allocation = $details['domain'] ?? null;

        return is_array($allocation) ? $allocation : [];
    }
}
