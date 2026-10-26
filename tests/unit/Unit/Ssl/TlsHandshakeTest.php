<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\TlsHandshake;
use PHPUnit\Framework\TestCase;

/**
 * A real handshake against `openssl s_server`, which serves one certificate by
 * default and another for one SNI name: the name sent decides what comes back.
 */
class TlsHandshakeTest extends TestCase
{
    private const NAMED = 'shop.example.com';

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

    public function test_the_name_is_sent_as_sni_and_decides_the_certificate(): void
    {
        $port = $this->startServer();
        $handshake = new TlsHandshake;

        $named = $handshake->presented('127.0.0.1', self::NAMED, $port);
        $other = $handshake->presented('127.0.0.1', 'other.example.com', $port);

        $this->assertNull($named['error']);
        $this->assertSame(self::NAMED, openssl_x509_parse((string) $named['leaf'])['subject']['CN']);
        $this->assertSame('default.example.com', openssl_x509_parse((string) $other['leaf'])['subject']['CN']);
    }

    public function test_a_closed_port_is_an_error_not_a_certificate(): void
    {
        $result = (new TlsHandshake)->presented('127.0.0.1', self::NAMED, $this->freePort());

        $this->assertNull($result['leaf']);
        $this->assertNotSame('', (string) $result['error']);
    }

    public function test_an_ipv6_literal_is_bracketed(): void
    {
        $this->assertSame('[2001:db8::1]', TlsHandshake::endpoint('2001:db8::1'));
        $this->assertSame('[2001:db8::1]', TlsHandshake::endpoint('[2001:db8::1]'));
        $this->assertSame('203.0.113.45', TlsHandshake::endpoint('203.0.113.45'));
    }

    private function startServer(): int
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('openssl binary not available');
        }

        $this->dir = sys_get_temp_dir().'/pa-tls-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writePair('default', TestCertificates::selfSigned(['default.example.com']));
        $this->writePair('named', TestCertificates::selfSigned([self::NAMED]));

        $port = $this->freePort();
        $this->server = proc_open([
            'openssl', 's_server', '-quiet', '-www', '-accept', "127.0.0.1:{$port}",
            '-cert', "{$this->dir}/default.crt", '-key', "{$this->dir}/default.key",
            '-servername', self::NAMED, '-cert2', "{$this->dir}/named.crt", '-key2', "{$this->dir}/named.key",
        ], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->waitForListener($port);

        return $port;
    }

    /**
     * @param  array{cert: string, key: string}  $pair
     */
    private function writePair(string $name, array $pair): void
    {
        file_put_contents("{$this->dir}/{$name}.crt", $pair['cert']);
        file_put_contents("{$this->dir}/{$name}.key", $pair['key']);
    }

    private function waitForListener(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
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
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
