<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\ServedCertificate;
use App\Lib\Ssl\TlsHandshake;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** What a real `openssl s_server` presents: names, issuer, expiry, self-signing, and the chain behind the leaf. */
class ServedCertificateHandshakeTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_a_certificate_signed_by_an_authority_reports_its_names_and_issuer(): void
    {
        $authority = TestCertificates::authority('Served Test Root');
        $port = $this->serve(TestCertificates::signedPairBy(['shop.example.com', 'www.shop.example.com'], $authority, 10));

        $served = ServedCertificate::at(TestNetwork::LOOPBACK.":{$port}");

        $this->assertNotNull($served);
        $this->assertSame('shop.example.com', $served['name']);
        $this->assertSame(['shop.example.com', 'www.shop.example.com'], $served['names']);
        $this->assertSame('Served Test Root', $served['issuer']);
        $this->assertFalse($served['self_signed']);
        $this->assertEqualsWithDelta(time() + 10 * 86400, $served['expires'], 120);
        $this->assertTrue(ServedCertificate::covers($served, 'www.shop.example.com'));
    }

    public function test_a_self_signed_certificate_is_reported_as_such_through_a_url(): void
    {
        $port = $this->serve(TestCertificates::selfSigned(['engine.example.com']));

        $served = ServedCertificate::at('https://'.TestNetwork::LOOPBACK.":{$port}/login");

        $this->assertNotNull($served);
        $this->assertSame('engine.example.com', $served['name']);
        $this->assertSame(['engine.example.com'], $served['names']);
        $this->assertSame('engine.example.com', $served['issuer']);
        $this->assertTrue($served['self_signed']);
    }

    public function test_the_handshake_returns_the_intermediates_without_the_leaf(): void
    {
        $root = TestCertificates::authority('Chain Test Root');
        $intermediate = TestCertificates::intermediate($root, 'Chain Test Intermediate');
        $pair = TestCertificates::signedPairBy(['shop.example.com'], $intermediate);
        $port = $this->serve($pair, $intermediate['cert']);

        $presented = (new TlsHandshake)->presented(TestNetwork::LOOPBACK, 'shop.example.com', $port);

        $this->assertNull($presented['error']);
        $this->assertSame(openssl_x509_fingerprint($pair['cert'], 'sha256'), openssl_x509_fingerprint((string) $presented['leaf'], 'sha256'));
        $this->assertCount(1, $presented['intermediates']);
        $this->assertSame(
            openssl_x509_fingerprint($intermediate['cert'], 'sha256'),
            openssl_x509_fingerprint($presented['intermediates'][0], 'sha256')
        );
    }

    /** @param  array{cert: string, key: string}  $pair */
    private function serve(array $pair, ?string $chain = null): int
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('openssl binary not available');
        }

        $this->dir = sys_get_temp_dir().'/pa-served-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/leaf.crt", $pair['cert']);
        file_put_contents("{$this->dir}/leaf.key", $pair['key']);

        $chainArgs = [];
        if ($chain !== null) {
            file_put_contents("{$this->dir}/chain.crt", $chain);
            $chainArgs = ['-cert_chain', "{$this->dir}/chain.crt"];
        }

        $port = $this->freePort();
        $this->server = proc_open([
            'openssl', 's_server', '-quiet', '-www', '-accept', TestNetwork::LOOPBACK.":{$port}",
            '-cert', "{$this->dir}/leaf.crt", '-key', "{$this->dir}/leaf.key", ...$chainArgs,
        ], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->waitForListener($port);

        return $port;
    }

    private function waitForListener(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen(TestNetwork::LOOPBACK, $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }

        $this->fail("openssl s_server did not start listening on {$port}");
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://'.TestNetwork::LOOPBACK.':0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
