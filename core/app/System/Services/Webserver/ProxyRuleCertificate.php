<?php

namespace App\System\Services\Webserver;

use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Lib\Ssl\KeyPair;
use App\Lib\Ssl\TrustStore;
use App\System;

/**
 * The certificate a proxy rule on 443 that no domain or alias is named after is served with.
 * Only the server's administrator provides one: the engine's own certificate, or a Let's Encrypt
 * lineage on the host, such as a wildcard obtained with DNS-01. A certificate on a project's domain
 * never counts, since any project may install one for any name. It must be publicly trusted, in
 * date and cover every name the rule answers for; without one the rule gets the host's default.
 */
final class ProxyRuleCertificate
{
    public const LETSENCRYPT_LIVE = '/etc/letsencrypt/live';

    public function __construct(
        private readonly System $system,
        private readonly string $lineagesDir = self::LETSENCRYPT_LIVE,
        private readonly ?string $caBundle = null,
        private readonly ?int $now = null,
    ) {}

    /**
     * The files of the trusted certificate that covers every name: the one naming most of them
     * exactly, then the one expiring last. sha256 is of the certificate file, so a renewal shows.
     *
     * @param  list<string>  $serverNames
     * @return array{pem: string, key: string, sha256: string}|null
     */
    public function covering(array $serverNames): ?array
    {
        $names = self::answeredNames($serverNames);
        if ($names === []) {
            return null;
        }
        foreach ($this->ranked($this->trustedCovering($names), $names) as $candidate) {
            if (KeyPair::matches($candidate['certificate'], (string) $this->system->filesystem()->cat($candidate['key']))) {
                return ['pem' => $candidate['pem'], 'key' => $candidate['key'], 'sha256' => hash('sha256', $candidate['certificate'])];
            }
        }

        return null;
    }

    /**
     * `.shop.test` answers for shop.test and every name under it; `_` or no name answers
     * for any name, which no certificate covers.
     *
     * @param  list<string>  $serverNames
     * @return list<string>
     */
    private static function answeredNames(array $serverNames): array
    {
        $names = [];
        foreach ($serverNames as $serverName) {
            $serverName = strtolower(trim($serverName));
            if ($serverName === '' || $serverName === '_') {
                return [];
            }
            array_push($names, ...(str_starts_with($serverName, '.') ? [substr($serverName, 1), '*'.$serverName] : [$serverName]));
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     * @return list<array{pem: string, key: string, certificate: string, facts: array<string, mixed>}>
     */
    private function trustedCovering(array $names): array
    {
        $covering = [];
        foreach ($this->candidateFiles() as $files) {
            $certificate = (string) $this->system->filesystem()->cat($files['pem']);
            $facts = CertificateFacts::fromPem($certificate);
            if ($facts !== null && TrustStore::verifies($certificate, $this->caBundle) === true
                && $this->trustedForAll(array_merge($facts, ['chain_trusted' => true]), $names)) {
                $covering[] = $files + ['certificate' => $certificate, 'facts' => $facts];
            }
        }

        return $covering;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $names
     */
    private function trustedForAll(array $facts, array $names): bool
    {
        foreach ($names as $name) {
            if (CertificateStatus::of($facts, $name, $this->now)['status'] !== CertificateStatus::TRUSTED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{pem: string, key: string, certificate: string, facts: array<string, mixed>}>  $candidates
     * @param  list<string>  $names
     * @return list<array{pem: string, key: string, certificate: string, facts: array<string, mixed>}>
     */
    private function ranked(array $candidates, array $names): array
    {
        usort($candidates, static fn (array $a, array $b): int => [self::exactNames($b, $names), $b['facts']['not_after'], $a['pem']]
            <=> [self::exactNames($a, $names), $a['facts']['not_after'], $b['pem']]);

        return $candidates;
    }

    /**
     * @param  array{facts: array<string, mixed>}  $candidate
     * @param  list<string>  $names
     */
    private static function exactNames(array $candidate, array $names): int
    {
        return count(array_intersect($names, array_map('strtolower', $candidate['facts']['domains'])));
    }

    /** @return list<array{pem: string, key: string}> */
    private function candidateFiles(): array
    {
        $engine = new EngineCertificate($this->system);
        $files = [['pem' => $engine->certificatePath(), 'key' => $engine->keyPath()]];
        foreach ($this->lineages() as $lineage) {
            $files[] = ['pem' => "{$this->lineagesDir}/{$lineage}/fullchain.pem", 'key' => "{$this->lineagesDir}/{$lineage}/privkey.pem"];
        }

        return $files;
    }

    /** @return list<string> */
    private function lineages(): array
    {
        $listing = $this->system->runProcess(['sudo', 'ls', '-1', $this->lineagesDir]);
        if (! $listing->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($listing->getOutput())),
            static fn (string $name): bool => preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $name) === 1,
        ));
    }
}
