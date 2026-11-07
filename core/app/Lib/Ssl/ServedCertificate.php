<?php

namespace App\Lib\Ssl;

/**
 * The certificate an address actually answers with: not the one on disk, nor the one last requested.
 */
final class ServedCertificate
{
    private const TIMEOUT = 5;

    /**
     * @param  string  $address  host:port, or a URL to take one from
     * @return array{name: string, names: array<int, string>, issuer: string, expires: int, self_signed: bool}|null null when nothing answered
     */
    public static function at(string $address): ?array
    {
        $target = self::hostPort($address);

        if ($target === null) {
            return null;
        }

        $certificate = self::peerCertificate($target);

        if ($certificate === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        if ($parsed === false) {
            return null;
        }

        return self::described($parsed);
    }

    /** Verification is off on purpose: an untrusted certificate is a thing to report, not a reason to refuse to look. */
    private static function peerCertificate(string $target): mixed
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $stream = @stream_socket_client(
            'ssl://'.$target,
            $errno,
            $error,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            return null;
        }

        $params = stream_context_get_params($stream);
        fclose($stream);

        return $params['options']['ssl']['peer_certificate'] ?? null;
    }

    /**
     * A self-signed certificate is the install default, not a failure.
     *
     * @param  array<string, mixed>  $parsed
     * @return array{name: string, names: array<int, string>, issuer: string, expires: int, self_signed: bool}
     */
    private static function described(array $parsed): array
    {
        $issuer = (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '');

        return [
            'name' => (string) ($parsed['subject']['CN'] ?? ''),
            'names' => self::alternativeNames($parsed),
            'issuer' => $issuer,
            'expires' => (int) ($parsed['validTo_time_t'] ?? 0),
            'self_signed' => ($parsed['subject'] ?? []) == ($parsed['issuer'] ?? []),
        ];
    }

    /** Whether a certificate covers a name, wildcards included. */
    public static function covers(array $certificate, string $name): bool
    {
        foreach ($certificate['names'] as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return true;
            }

            if (str_starts_with($candidate, '*.')
                && strcasecmp(substr($candidate, 1), substr($name, (int) strpos($name, '.'))) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<int, string>
     */
    private static function alternativeNames(array $parsed): array
    {
        $names = [];

        if (isset($parsed['subject']['CN']) && is_string($parsed['subject']['CN'])) {
            $names[] = $parsed['subject']['CN'];
        }

        $san = $parsed['extensions']['subjectAltName'] ?? '';

        foreach (explode(',', (string) $san) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $names[] = trim(substr($entry, 11));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /** host:port from either a bare `host:port` or a URL. */
    private static function hostPort(string $address): ?string
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        if (str_contains($address, '://')) {
            $host = parse_url($address, PHP_URL_HOST);
            $port = parse_url($address, PHP_URL_PORT);

            return is_string($host) && $host !== ''
                ? $host.':'.($port ?: 443)
                : null;
        }

        return str_contains($address, ':') ? $address : $address.':443';
    }
}
