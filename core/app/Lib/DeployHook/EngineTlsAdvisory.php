<?php

namespace App\Lib\DeployHook;

use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;

/**
 * Whether a git host will trust this engine's certificate when it calls the Deploy Hook, and if not,
 * how to make each provider call it anyway. Every non-trusted {@see CertificateStatus} is `self_signed` here.
 */
final class EngineTlsAdvisory
{
    public const VALID = 'valid';

    public const SELF_SIGNED = 'self_signed';

    /**
     * @param  ?string  $provider  narrow `instructions` to this one provider; null for all of them
     * @return array{state: string, warning: ?string, instructions: ?array<string, string>}
     */
    public static function forProvider(?string $provider = null): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $state = self::state($url, app(EngineCertificate::class));

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

    /** Read as `/system/info` reads it; no certificate to point to is no better than one nobody trusts. */
    private static function state(string $url, EngineCertificate $engine): string
    {
        $pem = $engine->served();
        $facts = $pem === null ? null : CertificateFacts::fromPem($pem);
        if ($facts === null) {
            return self::SELF_SIGNED;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $status = CertificateStatus::of($facts, $host);

        return $status['status'] === CertificateStatus::TRUSTED ? self::VALID : self::SELF_SIGNED;
    }

    /** A hostname is assumed reachable; a literal IP only outside the private and reserved ranges. */
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
