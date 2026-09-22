<?php

namespace App\Lib\DeployHook;

use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;

/**
 * What a client needs to know about the certificate a git host will see when
 * it calls this engine's Deploy Hook: whether it is one a git host trusts
 * outright, and, when it is not, how to make each supported provider call it
 * anyway.
 *
 * Deliberately collapses everything {@see CertificateStatus} can say into two
 * words. A caller here is not auditing the certificate -- it is deciding
 * whether a webhook delivery is going to fail on the TLS handshake before it
 * ever reaches this engine's own signature check, and every non-`trusted`
 * verdict (self-signed, expired, wrong name, an untrusted private CA, ...)
 * answers that question the same way: register anyway with per-provider
 * verification disabled, or fix the certificate first.
 */
final class EngineTlsAdvisory
{
    public const VALID = 'valid';
    public const SELF_SIGNED = 'self_signed';

    /**
     * @param ?string $provider narrow `instructions` to this one provider; null for all of them
     * @return array{state: string, warning: ?string, instructions: ?array<string, string>}
     */
    public static function forProvider(?string $provider = null): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $state = self::state($url);

        return [
            'state' => $state,
            'warning' => self::hasPublicAddress($url)
                ? null
                : 'This engine has no public address, so a git host on the internet cannot reach this Deploy Hook.',
            'instructions' => $state === self::SELF_SIGNED ? self::instructions($provider) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function instructions(?string $provider): array
    {
        if ($provider === null) {
            return TlsInstructions::all();
        }

        $text = TlsInstructions::for($provider);

        return $text === null ? [] : [$provider => $text];
    }

    private static function state(string $url): string
    {
        $engine = new EngineCertificate();
        $path = $engine->certificatePath();

        if (!is_readable($path)) {
            // No certificate to point to is no better than one nobody
            // trusts: either way the safe thing to tell a client is how to
            // get a git host past it.
            return self::SELF_SIGNED;
        }

        $facts = CertificateFacts::fromPem((string) file_get_contents($path));
        if ($facts === null) {
            return self::SELF_SIGNED;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $status = CertificateStatus::of($facts, $host);

        return $status['status'] === CertificateStatus::TRUSTED ? self::VALID : self::SELF_SIGNED;
    }

    /**
     * A hostname is assumed reachable -- DNS is not this class's business to
     * resolve. A literal IP is reachable only when it is outside the private
     * and reserved ranges nothing on the public internet can route to.
     */
    private static function hasPublicAddress(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
