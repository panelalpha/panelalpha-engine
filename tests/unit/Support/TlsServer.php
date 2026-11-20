<?php

namespace Tests\Support;

use RuntimeException;

/**
 * A TLS listener on a loopback address that hands out one certificate and
 * closes, run as a child PHP process so a handshake from the test can finish.
 */
final class TlsServer
{
    private const SERVE = <<<'PHP'
        $context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2], 'verify_peer' => false]]);
        $server = stream_socket_server('ssl://'.$argv[3].':0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) { fwrite(STDOUT, "failed: {$error}\n"); exit(1); }
        fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
        while (true) { $client = @stream_socket_accept($server, -1); if ($client) { fclose($client); } }
        PHP;

    private const SIGNING = ['digest_alg' => 'sha256'];

    /** @param resource $process */
    private function __construct(private $process, public readonly string $address) {}

    /**
     * @param  string  $host  the loopback address to listen on
     * @param  string  $name  the certificate's common name
     * @param  bool  $selfSigned  false signs it with a throwaway CA instead
     */
    public static function start(string $dir, string $host, string $name, int $days, bool $selfSigned = true): self
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => $name], $key, self::SIGNING);

        if ($selfSigned) {
            $certificate = openssl_csr_sign($request, null, $key, $days, self::SIGNING);
        } else {
            $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Wizard Test CA', 'organizationName' => 'Wizard Test CA'], $caKey, self::SIGNING), null, $caKey, $days, self::SIGNING);
            $certificate = openssl_csr_sign($request, $ca, $caKey, $days, self::SIGNING);
        }

        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($dir.'/tls.crt', $pem);
        file_put_contents($dir.'/tls.key', $keyPem);

        $process = proc_open(
            [PHP_BINARY, '-r', self::SERVE, $dir.'/tls.crt', $dir.'/tls.key', $host],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The TLS listener did not start.');
        }

        $address = trim((string) fgets($pipes[1]));

        if (! str_starts_with($address, $host.':')) {
            proc_terminate($process);

            throw new RuntimeException('The TLS listener did not start: '.$address);
        }

        return new self($process, $address);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
