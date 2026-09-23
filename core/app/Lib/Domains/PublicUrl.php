<?php

namespace App\Lib\Domains;

use App\System\Project\Dind\AppCertificate;
use App\Lib\Ssl\CertificateStatus;

/**
 * Whether a visitor can actually open the URL the deploy just reported.
 *
 * {@see \App\System\Project\Dind\AppHealth} answers the question
 * one layer down -- is the application serving -- and answers it deliberately
 * against `127.0.0.1`, so that the domain, DNS and TLS cannot colour a verdict
 * about the application itself. That is the right call there and it leaves a
 * gap here: an application that serves perfectly on loopback, under a name
 * that resolves nowhere, behind a certificate no browser accepts, finished as
 * a clean success and was reported to the fleet as one.
 *
 * The case that made it worth writing down is the licensing one. When Connect
 * will not sell a `panelalpha.online` label -- an expired key, an unreachable
 * proxy -- {@see DomainAllocator} does not fail the creation: it drops a rung
 * and the project is built under `<name>.local` with a self-signed
 * certificate. That is the correct behaviour (a project whose preferred name
 * was unavailable still gets a name) and it must not be silent, because from
 * the outside it is indistinguishable from a site that works.
 *
 * Both halves read what the deploy already recorded -- `details.domain` from
 * {@see AllocatedDomain::toDetails()} and `details.ssl` from
 * {@see AppCertificate} -- so nothing here probes anything.
 */
final class PublicUrl
{
    /**
     * TLS the engine does not serve.
     *
     * A `panelalpha.online` name is terminated at the WithoutDNS proxy, whose
     * certificate is the one a visitor is shown; what sits in the project's
     * `ssl-certs` is never presented to anybody. Judging that local
     * certificate would mark every working proxied site partial, which is the
     * false positive that would teach operators to ignore the field.
     */
    public const TLS_AT_PROXY = 'proxy';

    /**
     * Certificate verdicts a browser refuses to proceed past.
     *
     * `missing` and `unreadable` are deliberately not here. Missing is what a
     * domain with SSL switched off reports, and unreadable means the snapshot
     * failed rather than that the certificate is bad -- {@see AppCertificate}
     * is advisory, and a deploy that worked is not undone by a file we could
     * not parse.
     */
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
     * @param array<string, mixed> $details the account's details
     * @return list<string>
     */
    public static function warnings(string $domain, array $details): array
    {
        return array_values(array_filter([
            self::unresolvableWarning($domain, $details),
            self::certificateWarning($domain, $details),
        ]));
    }

    /**
     * A name that answers only on this host.
     *
     * Strictly `false`, never null. Null is what
     * {@see DomainAllocator::resolvability()} reports for a name whose DNS
     * this engine does not control -- an operator's own `sites_base_domain`,
     * or a domain the caller typed -- and those are working installs on every
     * self-hosted engine there is. Warning on "we cannot tell" would make
     * partial the normal outcome for them.
     *
     * @param array<string, mixed> $details
     */
    private static function unresolvableWarning(string $domain, array $details): ?string
    {
        $allocation = self::allocation($details);

        if (($allocation['publicly_resolvable'] ?? null) !== false) {
            return null;
        }

        // A `.direct` name on a private address is not the same claim as a
        // `.local` one: it resolves for every machine on that network, and
        // saying "this host only" would send an operator looking for a fault
        // in a name that works from the next VM along.
        $message = ($allocation['source'] ?? null) === DomainPlan::SOURCE_PANELALPHA_DIRECT
            ? "The application is deployed but not reachable from the internet: {$domain}"
                . " resolves to this host's private address, so it answers on this network only."
            : "The application is deployed but not reachable from the internet: {$domain}"
                . ' resolves on this host only.';

        // Why the better name was not had. This is the field that names the
        // licensing failure, and it is the whole reason an operator can tell
        // "Connect refused us" from "this engine has no public address".
        $reason = $allocation['fallback_reason'] ?? null;
        if (is_string($reason) && trim($reason) !== '') {
            $message .= ' The public name was skipped: ' . rtrim(trim($reason), '.') . '.';
        }

        return $message . ' Point a domain you control at this host, or redeploy once a public name can be allocated.';
    }

    /**
     * A certificate the engine serves and a browser will not accept.
     *
     * @param array<string, mixed> $details
     */
    private static function certificateWarning(string $domain, array $details): ?string
    {
        if ((self::allocation($details)['tls_terminated_at'] ?? null) === self::TLS_AT_PROXY) {
            return null;
        }

        $ssl = $details[AppCertificate::DETAIL] ?? null;
        $ssl = is_array($ssl) ? $ssl : [];

        $status = $ssl['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::REFUSED_BY_BROWSERS, true)) {
            return null;
        }

        $name = is_string($ssl['domain'] ?? null) && $ssl['domain'] !== '' ? $ssl['domain'] : $domain;
        $issuer = $ssl['issuer'] ?? null;

        return "https://{$name} is served a certificate browsers will refuse ({$status}"
            . (is_string($issuer) && $issuer !== '' && $issuer !== 'Unknown' ? ", issued by {$issuer}" : '')
            . '). Request a real one with `ssl:project-cert:request` once the name resolves to this host.';
    }

    /**
     * The `details.domain` block, or an empty one on a project created before
     * the allocator recorded it.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function allocation(array $details): array
    {
        $allocation = $details['domain'] ?? null;

        return is_array($allocation) ? $allocation : [];
    }
}
