<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * A certificate and a key that do not belong together are a mismatch nginx
 * will not report.
 *
 * `nginx -t` passes, nginx starts, nothing is logged, and then every TLS
 * handshake is refused with `SSL alert number 40`. On `crt/server.cert` that
 * takes the whole control-plane API on :2011 down, which reads as a network
 * fault.
 *
 * The PEMs are generated in the test rather than committed: a private key in
 * the repository is a secret in the repository, even a throwaway one.
 */
final class KeyPairTest extends TestCase
{
    private static ?array $pairA = null;
    private static ?array $pairB = null;

    /**
     * Two independent self-signed pairs. Same size and digest, so nothing
     * about the comparison can pass or fail on the shape of the key.
     *
     * @return array{cert: string, key: string}
     */
    private static function pair(string $cn): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'openssl could not generate a key');

        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr, 'openssl could not make a CSR');

        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert, 'openssl could not self-sign');

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem];
    }

    /** @return array{cert: string, key: string} */
    private function pairA(): array
    {
        return self::$pairA ??= self::pair('a.example.com');
    }

    /** @return array{cert: string, key: string} */
    private function pairB(): array
    {
        return self::$pairB ??= self::pair('b.example.com');
    }

    public function test_a_certificate_and_its_key_match(): void
    {
        $a = $this->pairA();

        $this->assertTrue(KeyPair::matches($a['cert'], $a['key']));
    }

    /**
     * The case that took :2011 down. Two valid, well-formed pairs -- just not
     * each other's.
     */
    public function test_a_certificate_and_another_keys_do_not_match(): void
    {
        $a = $this->pairA();
        $b = $this->pairB();

        $this->assertFalse(KeyPair::matches($a['cert'], $b['key']));
        $this->assertFalse(KeyPair::matches($b['cert'], $a['key']));
    }

    /** Each pair still matches itself, so the test above is not "all differ". */
    public function test_both_pairs_are_individually_valid(): void
    {
        foreach ([$this->pairA(), $this->pairB()] as $i => $pair) {
            $this->assertTrue(KeyPair::matches($pair['cert'], $pair['key']), 'pair ' . $i);
        }
    }

    /**
     * Anything unreadable is a mismatch, not a pass.
     *
     * A caller about to serve these files has to treat "cannot tell" the same
     * way as "does not match" -- there is no third answer that is safe.
     */
    public function test_unreadable_input_is_a_mismatch(): void
    {
        $a = $this->pairA();

        $this->assertFalse(KeyPair::matches('', ''));
        $this->assertFalse(KeyPair::matches($a['cert'], ''));
        $this->assertFalse(KeyPair::matches('', $a['key']));
        $this->assertFalse(KeyPair::matches('not a pem', 'not a pem'));
        $this->assertFalse(KeyPair::matches($a['cert'], 'not a pem'));
        $this->assertFalse(KeyPair::matches('not a pem', $a['key']));
    }

    /** A private key is not a certificate, and the other way round. */
    public function test_the_wrong_kind_of_pem_is_a_mismatch(): void
    {
        $a = $this->pairA();

        $this->assertFalse(KeyPair::matches($a['key'], $a['key']));
        $this->assertFalse(KeyPair::matches($a['cert'], $a['cert']));
    }

    /** A public key where a private key belongs is still not the pair. */
    public function test_a_public_key_is_not_a_private_key(): void
    {
        $a = $this->pairA();
        $public = KeyPair::publicKeyOfCertificate($a['cert']);
        $this->assertNotNull($public);

        $this->assertFalse(KeyPair::matches($a['cert'], (string) $public));
    }

    /** The accessors agree with matches() about what each side carries. */
    public function test_the_public_halves_are_equal_for_a_pair(): void
    {
        $a = $this->pairA();

        $this->assertSame(
            KeyPair::publicKeyOfCertificate($a['cert']),
            KeyPair::publicKeyOfPrivateKey($a['key'])
        );
    }

    public function test_the_public_halves_differ_across_pairs(): void
    {
        $this->assertNotSame(
            KeyPair::publicKeyOfCertificate($this->pairA()['cert']),
            KeyPair::publicKeyOfCertificate($this->pairB()['cert'])
        );
    }
}
