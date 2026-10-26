<?php

namespace Tests\Unit\Hub;

use App\Lib\Hub\EngineAddress;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubSigner;
use App\Lib\Hub\HubUrl;
use App\Lib\Hub\SpkiPin;
use Tests\TestCase;

/** The pieces with no database: signing, pinning, addresses, URLs, grants. */
class HubPrimitivesTest extends TestCase
{
    /** A15: the vector both sides of the protocol are pinned to. */
    public function test_the_signature_matches_the_known_answer_vector(): void
    {
        $body = '{"apps":[],"panel_version":"1.10.0"}';
        $secret = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';

        $this->assertSame('d2f7e89ca654f88bb58fe1d490856d7b327bfa3091e391f4789e4e9cbe430551', hash('sha256', $body));
        $this->assertSame(
            'f1b859734712722058731c0ec96c6b5a69ad4baf6cb54feb8c699b9b6ef850aa',
            HubSigner::sign('POST', 'api/panel/v1/report', '1749513600', $body, $secret)
        );
        // A leading slash or a query string is not part of what is signed.
        $this->assertSame(
            HubSigner::sign('POST', 'api/panel/v1/report', '1749513600', $body, $secret),
            HubSigner::sign('post', '/api/panel/v1/report?x=1', '1749513600', $body, $secret)
        );
        $this->assertStringEndsWith(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            HubSigner::canonical('GET', 'api/engine/v1/connection', '1', '')
        );

        $headers = HubSigner::headers('hc_1', $secret, 'POST', 'api/panel/v1/report', $body, 1749513600);
        $this->assertSame(['X-Server-Id' => 'hc_1', 'X-Timestamp' => '1749513600',
            'X-Signature' => 'f1b859734712722058731c0ec96c6b5a69ad4baf6cb54feb8c699b9b6ef850aa'], $headers);
    }

    /** A16 */
    public function test_the_pin_matches_openssl(): void
    {
        exec('command -v openssl', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('No openssl binary here to compare against.');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'x'], $key), null, $key, 1);
        openssl_x509_export($cert, $pem);
        $path = (string) tempnam(sys_get_temp_dir(), 'spki-');
        file_put_contents($path, $pem);

        $expected = trim((string) shell_exec(sprintf(
            'openssl x509 -pubkey -noout -in %s | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64',
            escapeshellarg($path)
        )));
        @unlink($path);

        $this->assertSame('sha256/' . $expected, SpkiPin::fromPem($pem));
    }

    public function test_an_unreadable_certificate_says_so(): void
    {
        $this->expectExceptionMessage('Cannot read the certificate');
        SpkiPin::fromFile('/nonexistent/server.cert');
    }

    public function test_public_addresses_pass(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2a01:4f8::10', '2606:4700::1111'] as $ip) {
            $this->assertNull(EngineAddress::problem($ip), $ip);
        }
        foreach (['example.com', '203.0.113.10', '192.0.2.1', '0.0.0.0', '224.0.0.1', '2001:db8::1', 'ff02::1'] as $ip) {
            $this->assertNotNull(EngineAddress::problem($ip), $ip);
        }
    }

    public function test_hub_url_rules(): void
    {
        $this->assertNull(HubUrl::problem('https://connect.panelalpha.com', 'production'));
        $this->assertNotNull(HubUrl::problem('http://connect.panelalpha.com', 'production'));
        $this->assertNotNull(HubUrl::problem('http://localhost', 'production'));
        $this->assertNull(HubUrl::problem('http://localhost:8000', 'local'));
        $this->assertNull(HubUrl::problem('http://127.0.0.1', 'local'));
        $this->assertNotNull(HubUrl::problem('http://10.0.0.1', 'local'));
        $this->assertNotNull(HubUrl::problem('https://user:pw@connect.panelalpha.com', 'production'));
        $this->assertNotNull(HubUrl::problem('', 'production'));
        $this->assertSame('https://hub.panelalpha.com', config('hub.url'), 'the default Hub');
        $this->assertSame('https://connect.panelalpha.com', config('connect.url'), 'WithoutDNS stays where it was');
    }

    public function test_unknown_grants_are_refused_not_ignored(): void
    {
        $this->assertSame([], HubGrants::unknown(['admin_sso']));
        $this->assertSame(['deploy'], HubGrants::unknown(['admin_sso', 'deploy']));
        $this->expectException(\App\Lib\Hub\HubException::class);
        HubGrants::abilities(['deploy']);
    }


}
