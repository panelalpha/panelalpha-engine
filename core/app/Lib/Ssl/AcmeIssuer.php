<?php

namespace App\Lib\Ssl;

use App\Lib\HttpAcmeChallengeStore;
use App\System;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use skoerfgen\ACMECert\ACMECert;

/**
 * A certificate from Let's Encrypt or any RFC 8555 authority over HTTP-01, served by the running webserver, as certbot
 * --standalone would take every site off :80. The vhost bakes the alias in: re-rendered before validation and after.
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
     * Only names no authority could issue for are refused, since a failed authorization counts anyway (Let's Encrypt:
     * 5 per account per hostname per hour). A shared-zone name passes: its reported budget is the operator's to spend.
     *
     * @see SharedZones
     */
    public function ineligibleReason(string $domain): ?string
    {
        return SharedZones::ineligibleReason($domain, $this->sharedZoneAllowed);
    }

    /** Reloads after installing: the proxy reads the certificate off disk, and keeps serving the old one until then. */
    public function issue(IssuableDomain $domain): void
    {
        $name = $domain->model()->domain;

        $reason = $this->ineligibleReason($name);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $client = $this->registeredClient();

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

        $intermediates = self::intermediates($parts);

        $domain->putCertificate($certificate, $leafKey, $intermediates);

        $this->publish($domain);
    }

    private function registeredClient(): ACMECert
    {
        $client = new ACMECert($this->directoryUrl);
        $client->setLogger(static function (string $message): void {
            Log::info('[acme] '.$message);
        });
        $client->loadAccountKey((new AcmeAccountKey($this->system))->pem());
        $client->register(true, $this->email === null || $this->email === '' ? [] : $this->email);

        return $client;
    }

    /**
     * Each part trimmed and newline-joined: the authority's PEM blocks do not all end in one.
     *
     * @param  array<mixed>  $parts
     */
    private static function intermediates(array $parts): string
    {
        return implode(PHP_EOL, array_map(
            static fn ($part): string => trim((string) $part),
            $parts
        ));
    }

    /**
     * Publish one challenge and return its removal, called in a `finally`; reloads follow the store's transitions, once per
     * issuance. A failed removal is only logged: `acme:challenge:prune` drops the token after 24 hours.
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
                    Log::warning("Could not remove the ACME challenge for {$name}: ".$e->getMessage());
                }
            };
        };
    }

    /**
     * The bare token the store is keyed by: the client hands back the whole `/.well-known/acme-challenge/<token>` path,
     * for its own docroot handler, and the store, which the vhost aliases, already owns the `.well-known` part.
     *
     * @param  array<string, mixed>  $opts
     */
    private static function token(array $opts): string
    {
        return basename((string) ($opts['key'] ?? ''));
    }

    /**
     * Re-render the vhost and reload, so the challenge alias written only while a token exists takes effect. Not through
     * {@see Domain::rebuild()}: it issues for a domain with no certificate, which would recurse from here on a new domain.
     */
    private function publish(IssuableDomain $domain): void
    {
        $domain->publishCertificateToHostWebserver();
    }
}
