<?php

namespace App\Lib\Ssl;

use App\Lib\HttpAcmeChallengeStore;
use App\System;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use skoerfgen\ACMECert\ACMECert;

/**
 * A real certificate for a project domain, from Let's Encrypt or any other
 * RFC 8555 authority, over HTTP-01.
 *
 * Almost all of this was already here. Every vhost template — apache, nginx,
 * litespeed, openlitespeed — already serves `/.well-known/acme-challenge/`
 * out of {@see HttpAcmeChallengeStore}, and {@see Domain::putCertificate()}
 * already installs a certificate where the proxy reads it. What was missing
 * was the half that talks to the authority, which is why the panel had to do
 * it from outside and a plain engine had no way to obtain one at all.
 *
 * Deliberately *not* the mechanism the engine's own admin certificate uses.
 * That one runs `certbot --standalone` and takes `sites-http` down to free
 * :80 — acceptable once, for one name; catastrophic per project, where it
 * would drop every hosted site on the box each time a certificate is issued
 * or renewed. Here the challenge is served by the running webserver, so
 * nothing goes offline and issuing for a hundred domains is a loop.
 *
 * The one subtlety is ordering. `http_acme_challenges_enabled` is baked into
 * the vhost when it is rendered, so writing the token is not enough: the
 * vhost has to be re-rendered and the webserver reloaded *before* the
 * authority is told to validate, and again after the token is removed. The
 * store reports the transition (`became_enabled` / `became_disabled`) so the
 * reload happens once per issuance rather than once per token.
 */
final class AcmeIssuer implements Issuer
{
    public const ID = 'acme';

    public const LETS_ENCRYPT = 'https://acme-v02.api.letsencrypt.org/directory';

    public const LETS_ENCRYPT_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    /** The certificate's own key. P-256 is the widest-supported EC curve for a leaf. */
    private const LEAF_CURVE = 'prime256v1';

    private HttpAcmeChallengeStore $store;

    private System $system;

    public function __construct(
        private readonly string $directoryUrl,
        private readonly ?string $email = null,
        ?HttpAcmeChallengeStore $store = null,
        ?System $system = null,
        private readonly bool $sharedZoneAllowed = false
    ) {
        $this->store = $store ?? new HttpAcmeChallengeStore;
        $this->system = $system ?? new System;
    }

    public function id(): string
    {
        return self::ID;
    }

    /**
     * Only the names no authority could ever issue for are refused, and only
     * because a failed authorization is spent whether or not it stood a
     * chance — Let's Encrypt counts 5 per account per hostname per hour.
     *
     * A name on a zone the fleet shares is *not* refused. It costs the shared
     * budget, which is reported where an operator can see it, and spending it
     * is their decision. {@see SharedZones}
     */
    public function ineligibleReason(string $domain): ?string
    {
        return SharedZones::ineligibleReason($domain, $this->sharedZoneAllowed);
    }

    public function issue(IssuableDomain $domain): void
    {
        $name = $domain->model()->domain;

        $reason = $this->ineligibleReason($name);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $client = new ACMECert($this->directoryUrl);
        $client->setLogger(static function (string $message): void {
            Log::info('[acme] '.$message);
        });
        $client->loadAccountKey((new AcmeAccountKey($this->system))->pem());
        $client->register(true, $this->email === null || $this->email === '' ? [] : $this->email);

        $leafKey = $client->generateECKey(self::LEAF_CURVE);
        $chain = $client->getCertificateChain(
            $leafKey,
            [$name => ['challenge' => 'http-01']],
            $this->challengeHandler($domain, $name)
        );

        $parts = $client->splitChain($chain);
        $certificate = trim((string) array_shift($parts));
        if ($certificate === '') {
            throw new RuntimeException("The authority returned no certificate for {$name}");
        }

        // Each intermediate separately trimmed and newline-joined: the
        // authority's chain arrives as PEM blocks that do not all end in one.
        $intermediates = implode(PHP_EOL, array_map(
            static fn ($part): string => trim((string) $part),
            $parts
        ));

        $domain->putCertificate($certificate, $leafKey, $intermediates);

        // The proxy reads the certificate straight off disk
        // (`<account>/ssl-certs/<domain>.pem`), so nothing but a reload stands
        // between installing it and serving it -- and without one the site
        // keeps answering with the certificate it had, which is how this
        // looked like a no-op the first time it worked.
        $this->publish($domain);
    }

    /**
     * Publish one challenge, make the webserver serve it, and hand back the
     * closure that takes it down again. The client calls the returned closure
     * in a `finally`, so a failed validation cleans up too.
     */
    private function challengeHandler(IssuableDomain $domain, string $name): \Closure
    {
        return function (array $opts) use ($domain, $name): \Closure {
            $result = $this->store->put($name, self::token($opts), (string) $opts['value']);
            if ($result['became_enabled']) {
                $this->publish($domain);
            }

            return function (array $opts) use ($domain, $name): void {
                try {
                    $removed = $this->store->delete($name, self::token($opts));
                    if ($removed['became_disabled']) {
                        $this->publish($domain);
                    }
                } catch (\Throwable $e) {
                    // A token left behind is served for 24 hours and then
                    // pruned by `acme:challenge:prune`. Failing the issuance
                    // over it would be the worse outcome.
                    Log::warning("Could not remove the ACME challenge for {$name}: ".$e->getMessage());
                }
            };
        };
    }

    /**
     * The bare token, which is what the store is keyed by.
     *
     * For http-01 the client hands back the whole request path --
     * `/.well-known/acme-challenge/<token>` -- because its own docroot
     * handler appends that to a document root. The store owns the
     * `.well-known` part already (the vhost aliases it), so only the last
     * segment belongs to it.
     *
     * @param  array<string, mixed>  $opts
     */
    private static function token(array $opts): string
    {
        return basename((string) ($opts['key'] ?? ''));
    }

    /**
     * Re-render the domain's vhost and reload, so the challenge alias the
     * template writes only when a token exists actually takes effect.
     *
     * The vhost is re-rendered directly rather than through
     * {@see Domain::rebuild()}, which issues a certificate when the domain
     * has none — the very call this issuer is running underneath. Going
     * through it would recurse until the stack ran out, on exactly the path
     * that matters: a brand new domain getting its first certificate.
     */
    private function publish(IssuableDomain $domain): void
    {
        $domain->publishCertificateToHostWebserver();
    }
}
