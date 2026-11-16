<?php

namespace App\Lib\Ssl;

/**
 * Where a domain's certificate comes from. An implementation installs what it issued (`Domain::putCertificate()`)
 * and throws when it cannot; falling back is for {@see Issuers} to decide, never the implementation.
 */
interface Issuer
{
    /**
     * The `ssl_issuer` setting value that selects this one.
     */
    public function id(): string;

    /**
     * Why this issuer cannot issue for the name, or null when it can. Asked first, so a failed authorization
     * is not spent against a rate limit that counts them.
     */
    public function ineligibleReason(string $domain): ?string;

    /**
     * Issue a certificate for the domain and install it on the account.
     *
     * @throws \Throwable when no certificate was installed
     */
    public function issue(IssuableDomain $domain): void;
}
