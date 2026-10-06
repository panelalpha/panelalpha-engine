<?php

namespace App\Lib\Ssl;

use App\System;

/**
 * The certificate the engine already holds, and whether a project may serve it.
 *
 * The engine's own certificate — `crt/server.cert`, what `:2011` presents —
 * is issued for the admin name. That covers nothing else, so historically
 * every project domain got one the engine signed itself, and the one exception
 * was written as string equality against the `vhost-default-ip-domain`
 * setting.
 *
 * A wildcard changes that completely. `*.203-0-113-45.panelalpha.direct`
 * covers every project on the host, and an engine that kept generating
 * self-signed certificates next to it would be ignoring the answer it already
 * had. The same is true of a wildcard an operator bought and installed by
 * hand today, which is why this is worth having before any of the DNS-01 work
 * that would produce one automatically.
 *
 * So the rule is coverage, not equality: if what the engine holds is valid
 * right now and names this domain — wildcard included — the project serves it.
 * Otherwise the project gets its own.
 *
 * Deliberately not clever about *which* certificate is better. There is one
 * candidate, and it either covers the name or it does not.
 */
final class EngineCertificate
{
    private System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System();
    }

    public function certificatePath(): string
    {
        return $this->system->engineDirPath() . '/crt/server.cert';
    }

    public function keyPath(): string
    {
        return $this->system->engineDirPath() . '/crt/server.key';
    }

    /** The certificate `:2011` presents, read the way covering() reads it; null when there is none. */
    public function served(): ?string
    {
        $fs = $this->system->filesystem();
        $path = $this->certificatePath();
        if (!$fs->fileExists($path)) {
            return null;
        }

        try {
            return $fs->fileGetContents($path);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The engine's certificate and key when they cover the domain and are in
     * date, else null.
     *
     * @return array{certificate: string, key: string, status: array<string, mixed>}|null
     */
    public function covering(string $domain, ?int $now = null): ?array
    {
        $certPath = $this->certificatePath();
        $keyPath = $this->keyPath();
        $fs = $this->system->filesystem();

        if (!$fs->fileExists($certPath) || !$fs->fileExists($keyPath)) {
            return null;
        }

        $certificate = trim((string) $fs->fileGetContents($certPath));
        $key = trim((string) $fs->fileGetContents($keyPath));

        if ($certificate === '' || $key === '') {
            return null;
        }

        $facts = CertificateFacts::fromPem($certificate);
        if ($facts === null) {
            return null;
        }

        // A certificate nobody holds the key to covers nothing. Both files can
        // be readable, valid and even in date while belonging to different
        // pairs -- the state a rotation left crt/server.cert in on a dev host,
        // where nginx started without a word and then refused every handshake
        // with `SSL alert number 40`. Serving that to a project would hand it
        // a certificate no client can complete a connection with, so the
        // project is better off signing its own.
        if (!KeyPair::matches($certificate, $key)) {
            return null;
        }

        $status = CertificateStatus::of($facts, $domain, $now);

        if (!self::isUsable($status)) {
            return null;
        }

        return ['certificate' => $certificate, 'key' => $key, 'status' => $status];
    }

    /**
     * Covering the name and in date. A self-signed engine certificate that
     * covers the name still qualifies: the project's alternative is a
     * self-signed certificate of its own, and one the whole host shares is no
     * worse and one fewer thing to renew.
     *
     * @param array<string, mixed> $status
     */
    private static function isUsable(array $status): bool
    {
        if (($status['covers_domain'] ?? false) !== true) {
            return false;
        }

        return !in_array(
            $status['status'] ?? null,
            [CertificateStatus::EXPIRED, CertificateStatus::NOT_YET_VALID, CertificateStatus::UNREADABLE],
            true
        );
    }
}
