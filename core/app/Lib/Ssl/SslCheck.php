<?php

namespace App\Lib\Ssl;

use App\Lib\Http\OutboundUrl;
use App\Models\Domain;
use App\System\Project\PhpHosting\WordPress\Migration\DestinationProbe;

/**
 * The certificate a domain really serves, read by connecting to it.
 *
 * The installed-certificate routes read the file on disk, which is what the
 * webserver was told to load, not what a visitor gets: a renewal the
 * webserver never reloaded, DNS pointing at another server, or a CDN in front
 * all serve something else. So this asks twice, both times over a real TLS
 * handshake with the domain as SNI:
 *
 * - `public`: at the address the domain's DNS gives, which is what a visitor gets;
 * - `engine`: at the address this project's webserver listens on, which is what
 *   this host serves whatever DNS says.
 *
 * `status` is the visitor's answer: the certificate verdict from
 * {@see CertificateStatus}, or why there was no certificate to judge.
 */
final class SslCheck
{
    /** The domain has no A or AAAA record. */
    public const NOT_RESOLVING = 'not_resolving';

    /** DNS points at an address core must not contact, such as a private range not this host's. */
    public const REFUSED_ADDRESS = 'refused_address';

    /** Nothing completed a TLS handshake at the resolved address. */
    public const UNREACHABLE = 'unreachable';

    public function __construct(
        private readonly TlsHandshake $handshake = new TlsHandshake,
        private readonly ?int $now = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forDomain(Domain $model): array
    {
        $addresses = $model->user?->getIpAddresses() ?? ['ipv4' => [], 'ipv6' => []];

        return $this->check(
            $model->domain,
            array_values(array_merge($addresses['ipv4'], $addresses['ipv6'])),
            DestinationProbe::engineAddress($model->domain),
            self::installedCertificate($model),
        );
    }

    /**
     * @param  list<string>  $expected  the public addresses the domain should resolve to
     * @param  ?string  $engineAddress  the address this project's webserver listens on
     * @param  ?string  $installedPem  the certificate on disk for the domain
     * @return array<string, mixed>
     */
    public function check(string $domain, array $expected, ?string $engineAddress, ?string $installedPem): array
    {
        $resolved = OutboundUrl::resolve($domain);
        $public = $resolved === [] ? null : $this->publicProbe($domain, $resolved[0], $expected);
        $engine = $engineAddress === null ? null : $this->engineProbe($domain, $engineAddress, $installedPem);

        return [
            'domain' => $domain,
            'status' => self::status($public),
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            ...self::summary($public['certificate'] ?? null),
            'dns' => [
                'addresses' => $resolved,
                'expected' => $expected,
                'points_here' => $resolved !== [] && array_diff($resolved, $expected) === [],
            ],
            'public' => $public,
            'engine' => $engine,
        ];
    }

    /**
     * @param  list<string>  $expected
     * @return array<string, mixed>
     */
    private function publicProbe(string $domain, string $address, array $expected): array
    {
        if (! in_array($address, $expected, true) && OutboundUrl::refusesAddress($address)) {
            return self::refused($address);
        }

        return $this->probe($domain, $address);
    }

    /**
     * @return array<string, mixed>
     */
    private function engineProbe(string $domain, string $address, ?string $installedPem): array
    {
        $probe = $this->probe($domain, $address);
        $served = $probe['certificate']['fingerprint_sha256'] ?? null;

        return $probe + [
            'serves_installed' => $served === null || $installedPem === null
                ? null
                : $served === self::fingerprint($installedPem),
        ];
    }

    /**
     * @return array{address: string, answered: bool, refused: bool, error: ?string, certificate: ?array<string, mixed>}
     */
    private function probe(string $domain, string $address): array
    {
        $presented = $this->handshake->presented($address, $domain);

        return [
            'address' => $address,
            'answered' => $presented['leaf'] !== null,
            'refused' => false,
            'error' => $presented['error'],
            'certificate' => $presented['leaf'] === null
                ? null
                : $this->certificate($presented['leaf'], $presented['intermediates'], $domain),
        ];
    }

    /**
     * @param  list<string>  $intermediates
     * @return array<string, mixed>
     */
    private function certificate(string $leaf, array $intermediates, string $domain): array
    {
        $facts = CertificateFacts::fromPem($leaf, $intermediates === [] ? null : implode(PHP_EOL, $intermediates));
        if ($facts === null) {
            return ['status' => CertificateStatus::UNREADABLE, 'fingerprint_sha256' => self::fingerprint($leaf)];
        }

        $details = CertificateDetails::of($leaf);

        return CertificateStatus::of($facts, $domain, $this->now()) + [
            'common_name' => $facts['common_name'],
            'names' => $facts['domains'],
            'signer' => $details['signer'] ?? null,
            'serial_number' => $details['serial_number'] ?? null,
            'signature_algorithm' => $details['signature_algorithm'] ?? null,
            'key' => $details['key'] ?? null,
            'fingerprint_sha256' => self::fingerprint($leaf),
            'chain' => array_map(self::chainLink(...), $intermediates),
        ];
    }

    /**
     * The certificates the server sent after the leaf, in its order.
     *
     * @return array{subject: ?array<string, ?string>, signer: ?array<string, ?string>, expires_at: ?string}
     */
    private static function chainLink(string $pem): array
    {
        $details = CertificateDetails::of($pem);

        return [
            'subject' => $details['subject'] ?? null,
            'signer' => $details['signer'] ?? null,
            'expires_at' => $details['expires_at'] ?? null,
        ];
    }

    /**
     * Expiry and signer of what a visitor gets, lifted to the top so a caller need not dig for them.
     *
     * @param  ?array<string, mixed>  $certificate
     * @return array{issuer: ?string, signer: mixed, valid_from: ?string, expires_at: ?string, days_remaining: ?int}
     */
    private static function summary(?array $certificate): array
    {
        return [
            'issuer' => $certificate['issuer'] ?? null,
            'signer' => $certificate['signer'] ?? null,
            'valid_from' => $certificate['valid_from'] ?? null,
            'expires_at' => $certificate['expires_at'] ?? null,
            'days_remaining' => $certificate['days_remaining'] ?? null,
        ];
    }

    /**
     * @return array{address: string, answered: bool, refused: bool, error: string, certificate: null}
     */
    private static function refused(string $address): array
    {
        return [
            'address' => $address,
            'answered' => false,
            'refused' => true,
            'error' => "The domain resolves to {$address}, an address the engine does not contact.",
            'certificate' => null,
        ];
    }

    /**
     * @param  ?array<string, mixed>  $public
     */
    private static function status(?array $public): string
    {
        if ($public === null) {
            return self::NOT_RESOLVING;
        }
        if ($public['refused']) {
            return self::REFUSED_ADDRESS;
        }
        if (! $public['answered']) {
            return self::UNREACHABLE;
        }

        return (string) ($public['certificate']['status'] ?? CertificateStatus::UNREADABLE);
    }

    private static function installedCertificate(Domain $model): ?string
    {
        $hosted = $model->projectDomain();
        if (! $hosted->hasSslCertificate()) {
            return null;
        }

        $pem = (string) ($hosted->getSslCertificateInfo()['certificate'] ?? '');

        return trim($pem) === '' ? null : $pem;
    }

    private static function fingerprint(string $pem): ?string
    {
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        return is_string($fingerprint) ? $fingerprint : null;
    }

    private function now(): int
    {
        return $this->now ?? time();
    }
}
