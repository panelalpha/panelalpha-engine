<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\AcmeAccountKey;
use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;

/** The ACME account key: read when stored, generated once and written at 0600 when not. */
class AcmeAccountKeyTest extends TestCase
{
    private const PATH = '/engine/crt/acme-account.key';

    /** @param  array<string, string>  $files */
    private static function host(array $files = []): System
    {
        $system = new class extends System
        {
            /** @var array<string, string> */
            public array $files = [];

            /** @var list<string> */
            public array $writes = [];

            public function engineDirPath(): string
            {
                return '/engine';
            }

            public function filesystem(): Filesystem
            {
                $host = $this;

                return new class($host) extends Filesystem
                {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->host->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path];
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        $this->host->writes[] = "mkdir {$target}";
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                        $this->host->writes[] = "put {$path} {$chmod}";
                    }
                };
            }
        };
        $system->files = $files;

        return $system;
    }

    public function test_the_key_lives_beside_the_engine_certificates(): void
    {
        $this->assertSame(self::PATH, (new AcmeAccountKey(self::host()))->path());
    }

    public function test_a_stored_key_is_returned_trimmed_and_nothing_is_written(): void
    {
        $system = self::host([self::PATH => "  -----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----\n\n"]);

        $this->assertSame(
            "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----",
            (new AcmeAccountKey($system))->pem()
        );
        $this->assertSame([], $system->writes);
    }

    public function test_a_missing_key_is_generated_as_p384_and_stored_private(): void
    {
        $system = self::host();
        $key = new AcmeAccountKey($system);

        $pem = $key->pem();

        $this->assertSame(['mkdir /engine/crt', 'put '.self::PATH.' 600'], $system->writes);
        $this->assertSame($pem.PHP_EOL, $system->files[self::PATH]);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
        $this->assertSame(OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertSame('secp384r1', $details['ec']['curve_name']);
        $this->assertSame($pem, $key->pem());
        $this->assertCount(2, $system->writes);
    }

    public function test_an_empty_stored_key_is_replaced(): void
    {
        $system = self::host([self::PATH => "\n"]);

        $pem = (new AcmeAccountKey($system))->pem();

        $this->assertStringStartsWith('-----BEGIN', $pem);
        $this->assertSame($pem.PHP_EOL, $system->files[self::PATH]);
    }
}
