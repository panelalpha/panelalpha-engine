<?php

namespace App\Lib\Ssl;

/**
 * The certificate the engine signs itself: what every project domain has had
 * since there were project domains.
 *
 * It is not a fallback in the apologetic sense. A hosting account exists
 * before its DNS points anywhere, and a name that does not resolve to this
 * host cannot pass an HTTP-01 challenge — so there is a window on every new
 * project where no authority will issue anything, and the site still has to
 * answer on :443. This fills it, and keeps filling it for the domains a
 * public authority will never certify: a shared wildcard zone, a name behind
 * a VPN, a test host.
 *
 * Always eligible, by definition: it asks nobody's permission.
 */
final class SelfSignedIssuer implements Issuer
{
    public const ID = 'self_signed';

    public function id(): string
    {
        return self::ID;
    }

    public function ineligibleReason(string $domain): ?string
    {
        return null;
    }

    public function issue(IssuableDomain $domain): void
    {
        $domain->generateSelfSignedCertificate();
    }
}
