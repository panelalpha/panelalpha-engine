<?php

namespace App\Lib\Hub;

use App\Lib\Ssl\EngineCertificate;

/**
 * `sha256/<base64>` of the certificate's DER SubjectPublicKeyInfo: how the Hub
 * knows it is talking to this engine on :2011 when the certificate is
 * self-signed or issued for a bare IP.
 */
final class SpkiPin
{
    /** Where core's nginx reads the :2011 certificate. */
    public const SERVED_CERT = '/root/crt/server.cert';

    /** Tests point this at a certificate of their own. */
    public static ?string $servedPathOverride = null;

    public static function servedCertificatePath(): string
    {
        if (self::$servedPathOverride !== null) {
            return self::$servedPathOverride;
        }

        return is_readable(self::SERVED_CERT) ? self::SERVED_CERT : (new EngineCertificate)->certificatePath();
    }

    /** @throws HubException */
    public static function fromFile(string $path): string
    {
        $pem = is_readable($path) ? @file_get_contents($path) : false;
        if (! is_string($pem) || trim($pem) === '') {
            throw new HubException("Cannot read the certificate at {$path}.");
        }

        return self::fromPem($pem);
    }

    /**
     * The PEM public key is the SubjectPublicKeyInfo, base64 between the markers.
     *
     * @throws HubException
     */
    public static function fromPem(string $pem): string
    {
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new HubException('The certificate has no readable public key.');
        }

        $body = preg_replace('/-----[^-]+-----|\s+/', '', $details['key']);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') {
            throw new HubException('The certificate has no readable public key.');
        }

        return 'sha256/'.base64_encode(hash('sha256', $der, true));
    }
}
