<?php

namespace App\Lib\Ssl;

/**
 * One TLS handshake with an address, the name sent as SNI, returning what the
 * server presents.
 *
 * Verification is off on purpose: an expired, self-signed or wrong-name
 * certificate is the answer being looked for, not a reason to hang up.
 */
class TlsHandshake
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * The leaf is null, with the reason in error, when nothing answered or the handshake failed.
     *
     * @return array{leaf: ?string, intermediates: list<string>, error: ?string}
     */
    public function presented(string $address, string $serverName, int $port = 443): array
    {
        error_clear_last();
        $stream = @stream_socket_client(
            'ssl://'.self::endpoint($address).':'.$port,
            $errno,
            $error,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            self::context($serverName),
        );

        if ($stream === false) {
            return ['leaf' => null, 'intermediates' => [], 'error' => self::failure($errno, $error)];
        }

        $ssl = stream_context_get_params($stream)['options']['ssl'] ?? [];
        fclose($stream);

        return self::certificates($ssl);
    }

    /** The stream-URI host for an address: an IPv6 literal must be bracketed. */
    public static function endpoint(string $address): string
    {
        $address = trim($address, '[]');

        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    /**
     * @return resource
     */
    private static function context(string $serverName)
    {
        return stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $serverName,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $ssl
     * @return array{leaf: ?string, intermediates: list<string>, error: ?string}
     */
    private static function certificates(array $ssl): array
    {
        $leaf = self::pem($ssl['peer_certificate'] ?? null);
        if ($leaf === null) {
            return ['leaf' => null, 'intermediates' => [], 'error' => 'The server completed the handshake without presenting a certificate.'];
        }

        $leafFingerprint = openssl_x509_fingerprint($leaf, 'sha256');
        $intermediates = [];
        foreach ((array) ($ssl['peer_certificate_chain'] ?? []) as $certificate) {
            $pem = self::pem($certificate);
            if ($pem !== null && openssl_x509_fingerprint($pem, 'sha256') !== $leafFingerprint) {
                $intermediates[] = $pem;
            }
        }

        return ['leaf' => $leaf, 'intermediates' => $intermediates, 'error' => null];
    }

    private static function pem(mixed $certificate): ?string
    {
        if (! $certificate instanceof \OpenSSLCertificate) {
            return null;
        }

        return openssl_x509_export($certificate, $pem) ? $pem : null;
    }

    /** The socket's own reason, or the TLS layer's, which PHP only raises as a warning. */
    private static function failure(int $errno, string $error): string
    {
        if (trim($error) !== '') {
            return trim($error);
        }

        $warning = error_get_last()['message'] ?? '';
        $warning = trim((string) preg_replace('/^stream_socket_client\(\):\s*/', '', $warning));

        return $warning !== '' ? $warning : "TLS handshake failed (error {$errno}).";
    }
}
