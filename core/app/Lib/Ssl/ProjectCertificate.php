<?php

namespace App\Lib\Ssl;

use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use RuntimeException;

/**
 * A certificate for one project domain on demand: one sequence for `ssl:project-cert:request` and `POST …/request-ssl-cert`
 * so neither drifts, and one for `ssl:project-cert:remove` and `DELETE …/installed-ssl-cert`.
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
        $runtime = $user?->project(app(System::class))->runtime();
        if ($runtime instanceof Dind) {
            $runtime->appCertificate()->remember();
        }
    }

    /**
     * Drop the certificate installed on a domain and give it the one a new domain gets: the engine's own where that
     * covers the name, otherwise what the issuer setting names. The old one's ACME state goes with it. A domain already
     * on a certificate the engine made keeps it, so nothing is issued again.
     *
     * @return array<string, mixed> the certificate the domain carries now, as `installed-ssl-cert` reports it
     *
     * @throws ProblemException ssl_disabled, when the domain has SSL turned off and so no certificate to put back
     */
    public static function remove(Domain $domain): array
    {
        if (! $domain->sslEnabled()) {
            throw ProblemException::one(
                'domain',
                'ssl_disabled',
                "{$domain->domain} has SSL turned off, so it has no certificate to put back."
            );
        }

        $connection = $domain->getUser()->project(app(System::class))->domain($domain);
        if (! $connection->carriesEngineCertificate()) {
            $connection->resetCertificate();
            self::remember($domain->getUser());
        }

        return $connection->getSslCertificateInfo();
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
