<?php

namespace Tests\Feature;

use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class DomainsSslTest extends TestCase
{
    /**
     * @return array{cert: string, key: string}
     */
    private function generateSelfSignedCert(string $commonName): array
    {
        $privKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        assert($privKey !== false, 'Failed to generate private key');

        $csr = openssl_csr_new(
            ['commonName' => $commonName],
            $privKey,
            ['digest_alg' => 'sha256']
        );
        assert($csr !== false, 'Failed to generate CSR');

        $x509 = openssl_csr_sign($csr, null, $privKey, 365, ['digest_alg' => 'sha256']);
        assert($x509 !== false, 'Failed to sign certificate');

        $certPem = '';
        $keyPem = '';
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem];
    }

    #[SetsCache('ssl_cert')]
    #[SetsCache('ssl_key')]
    public function test_install_ssl_cert(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $ssl = $this->generateSelfSignedCert($domainName);
        $this->setCache('ssl_cert', $ssl['cert']);
        $this->setCache('ssl_key', $ssl['key']);

        $response = $this->putJson("/api/users/{$username}/domains/{$domainName}/install-ssl-cert", [
            'cert' => $ssl['cert'],
            'key'  => $ssl['key'],
        ]);
        $response->assertStatus(200);
    }

    public function test_installed_ssl_cert_details(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}/installed-ssl-cert");
        $response->assertStatus(200);
        $response->assertJsonPath('data.common_name', $domainName);
        $response->assertJsonStructure([
            'data' => ['common_name', 'issuer_name', 'issuer_common_name', 'not_before', 'not_after'],
        ]);
    }

    public function test_reinstall_ssl_cert(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $cert = $this->getCache('ssl_cert');
        $key = $this->getCache('ssl_key');
        assert(is_string($cert));
        assert(is_string($key));

        $response = $this->putJson("/api/users/{$username}/domains/{$domainName}/install-ssl-cert", [
            'cert' => $cert,
            'key'  => $key,
        ]);
        $response->assertStatus(200);
    }
}
