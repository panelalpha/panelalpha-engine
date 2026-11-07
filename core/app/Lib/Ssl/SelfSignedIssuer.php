<?php

namespace App\Lib\Ssl;

/**
 * The certificate the engine signs itself, always eligible: it covers a new project before its DNS points here
 * (no HTTP-01 possible), and the names no public authority will certify (a shared wildcard zone, a VPN, a test host).
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
