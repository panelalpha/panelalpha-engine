<?php

namespace App\Lib\Ssl;

use App\System\Project\Dind;
use App\Models\Domain;
use App\Models\User;
use RuntimeException;

/**
 * Obtaining a certificate for one project domain, on demand.
 *
 * The steps are few but none of them is optional, and there are two callers —
 * `ssl:project-cert:request` and `POST …/request-ssl-cert`. Issuing without
 * re-rendering leaves the old certificate being served; re-rendering without
 * recording the snapshot leaves `details.ssl` saying `self_signed` about a
 * certificate that is nothing of the sort, which is the field everything
 * downstream reads. A copy of that sequence in each caller is a copy that
 * will drift.
 */
final class ProjectCertificate
{
    /**
     * Request, install and report. The array is the same shape
     * {@see CertificateStatus} returns everywhere else.
     *
     * @throws RuntimeException when the name is one no authority will issue
     *         for; the caller turns that into its own kind of refusal
     * @throws \Throwable when the authority declined — the site keeps the
     *         certificate it already had
     *
     * @return array<string, mixed>
     */
    public static function request(Domain $domain, bool $staging = false): array
    {
        $name = $domain->domain;
        $issuer = self::issuer($staging);

        $reason = $issuer->ineligibleReason($name);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $connection = $domain->projectDomain();
        $issuer->issue($connection);

        // The vhost is re-rendered with the new certificate in place, and the
        // project's snapshot updated: `details.ssl` is what an API caller
        // reads, and it would otherwise describe the certificate this just
        // replaced until the next deploy.
        $connection->rebuild();
        self::remember($domain->user);

        return CertificateStatus::of($connection->getSslCertificateInfo(), $name);
    }

    /**
     * Refresh a DinD project's `details.ssl` after its certificate changed.
     * User::project() is the System\Project wrapper; the DinD driver is its runtime().
     */
    public static function remember(?User $user): void
    {
        $runtime = $user?->project()->runtime();
        if ($runtime instanceof Dind) {
            $runtime->appCertificate()->remember();
        }
    }

    /**
     * What a request would do, without doing it.
     *
     * @return array<string, mixed>
     */
    public static function plan(Domain $domain, bool $staging = false): array
    {
        $name = $domain->domain;

        return [
            'domain' => $name,
            'authority' => $staging ? AcmeIssuer::LETS_ENCRYPT_STAGING : Issuers::directoryUrl(),
            'account_email' => Issuers::email(),
            'challenge' => 'http-01',
            'challenge_url' => "http://{$name}/.well-known/acme-challenge/",
            'ineligible_reason' => self::issuer($staging)->ineligibleReason($name),
        ];
    }

    /**
     * Staging keeps its own accounting and its own trust, so it is the way to
     * rehearse a request without spending anything that counts. Renewal asks
     * here too, so it follows the same shared-zone rule as the first request.
     */
    public static function issuer(bool $staging): AcmeIssuer
    {
        // The shared-zone decision comes from the setting here too. Building
        // an AcmeIssuer directly is how this path skipped it once already:
        // the constructor's default is "not allowed", so a deliberate
        // `ssl:project-cert:request` refused a name the operator had just
        // enabled. One setting, asked wherever an issuer is made.
        $allowed = Issuers::sharedZoneIssuanceAllowed();

        return $staging
            ? new AcmeIssuer(AcmeIssuer::LETS_ENCRYPT_STAGING, Issuers::email(), null, null, $allowed)
            : new AcmeIssuer(Issuers::directoryUrl(), Issuers::email(), null, null, $allowed);
    }
}
