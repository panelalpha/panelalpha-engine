<?php

namespace App\Lib\Ssl;

use App\Models\Domain;
use App\Models\User;
use App\System\Project\Dind;
use RuntimeException;

/**
 * A certificate for one project domain on demand, one sequence for `ssl:project-cert:request` and `POST …/request-ssl-cert`
 * so neither drifts: without the re-render the old one is served, without the snapshot `details.ssl` describes the old one.
 */
final class ProjectCertificate
{
    /**
     * Request, install and report. The array is the same shape
     * {@see CertificateStatus} returns everywhere else.
     *
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the name is one no authority will issue
     *                          for; the caller turns that into its own kind of refusal
     * @throws \Throwable when the authority declined — the site keeps the
     *                    certificate it already had
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
     * Staging keeps its own accounting and trust, so a request is rehearsed there without spending anything that counts.
     * Renewal asks here too; the shared-zone setting is read here, as the constructor's default refuses names it allows.
     */
    public static function issuer(bool $staging): AcmeIssuer
    {
        $allowed = Issuers::sharedZoneIssuanceAllowed();

        return $staging
            ? new AcmeIssuer(AcmeIssuer::LETS_ENCRYPT_STAGING, Issuers::email(), null, null, $allowed)
            : new AcmeIssuer(Issuers::directoryUrl(), Issuers::email(), null, null, $allowed);
    }
}
