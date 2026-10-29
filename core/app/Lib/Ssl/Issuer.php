<?php

namespace App\Lib\Ssl;

/**
 * Where a domain's certificate comes from.
 *
 * The engine had exactly one answer — sign it ourselves — written into
 * {@see Domain::generateCertificate()}, which is why every project site was
 * served with a certificate no browser accepts and nothing in the API said
 * so. Making it an interface is the point of the exercise: an operator who
 * wants Let's Encrypt sets one setting, an operator with an internal CA
 * writes one class, and the engine's own decision about *when* to issue does
 * not move.
 *
 * An implementation installs what it issued (`Domain::putCertificate()`) and
 * throws when it cannot. It never falls back — choosing what to do about a
 * failure belongs to {@see Issuers}, which has the self-signed certificate to
 * fall back to and the deploy that must not fail either way.
 */
interface Issuer
{
    /**
     * The `ssl_issuer` setting value that selects this one.
     */
    public function id(): string;

    /**
     * Whether this issuer can issue for the name at all, and why not.
     *
     * Asked before issuing, so the caller can say "not attempted, because…"
     * instead of spending a failed authorization against a rate limit that
     * counts them. Null means it can.
     */
    public function ineligibleReason(string $domain): ?string;

    /**
     * Issue a certificate for the domain and install it on the account.
     *
     * @throws \Throwable when no certificate was installed
     */
    public function issue(IssuableDomain $domain): void;
}
