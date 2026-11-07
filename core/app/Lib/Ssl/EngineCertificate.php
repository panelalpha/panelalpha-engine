<?php

namespace App\Lib\Ssl;

use App\System;

/**
 * The certificate the engine holds, and whether a project may serve it: coverage, not equality,
 * so a wildcard over the host serves every project it names.
 */
final class EngineCertificate
{
    private System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System;
    }

    public function certificatePath(): string
    {
        return $this->system->engineDirPath().'/crt/server.cert';
    }

    public function keyPath(): string
    {
        return $this->system->engineDirPath().'/crt/server.key';
    }

    /** The certificate `:2011` presents, read the way covering() reads it; null when there is none. */
    public function served(): ?string
    {
        $fs = $this->system->filesystem();
        $path = $this->certificatePath();
        if (! $fs->fileExists($path)) {
            return null;
        }

        try {
            return $fs->fileGetContents($path);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The engine's certificate and key when they cover the domain and are in date, else null.
     * A certificate whose key is not its pair (a rotation can leave that) covers nothing.
     *
     * @return array{certificate: string, key: string, status: array<string, mixed>}|null
     */
    public function covering(string $domain, ?int $now = null): ?array
    {
        $pair = $this->heldPair();
        if ($pair === null) {
            return null;
        }
        [$certificate, $key] = $pair;

        $facts = CertificateFacts::fromPem($certificate);
        if ($facts === null) {
            return null;
        }

        if (! KeyPair::matches($certificate, $key)) {
            return null;
        }

        $status = CertificateStatus::of($facts, $domain, $now);

        if (! self::isUsable($status)) {
            return null;
        }

        return ['certificate' => $certificate, 'key' => $key, 'status' => $status];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function heldPair(): ?array
    {
        $certPath = $this->certificatePath();
        $keyPath = $this->keyPath();
        $fs = $this->system->filesystem();

        if (! $fs->fileExists($certPath) || ! $fs->fileExists($keyPath)) {
            return null;
        }

        $certificate = trim((string) $fs->fileGetContents($certPath));
        $key = trim((string) $fs->fileGetContents($keyPath));

        if ($certificate === '' || $key === '') {
            return null;
        }

        return [$certificate, $key];
    }

    /**
     * Covering the name and in date; a self-signed engine certificate still beats the project's own.
     *
     * @param  array<string, mixed>  $status
     */
    private static function isUsable(array $status): bool
    {
        if (($status['covers_domain'] ?? false) !== true) {
            return false;
        }

        return ! in_array(
            $status['status'] ?? null,
            [CertificateStatus::EXPIRED, CertificateStatus::NOT_YET_VALID, CertificateStatus::UNREADABLE],
            true
        );
    }
}
