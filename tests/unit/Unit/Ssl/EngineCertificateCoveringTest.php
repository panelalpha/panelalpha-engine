<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\System;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;

/** Pins covering() on a held certificate and key, read through a faked filesystem. */
class EngineCertificateCoveringTest extends TestCase
{
    private const CERT = '/opt/panelalpha/shared-hosting/crt/server.cert';

    private const KEY = '/opt/panelalpha/shared-hosting/crt/server.key';

    /** @param  array<string, string>  $files */
    private static function engine(array $files): EngineCertificate
    {
        $system = new class extends System
        {
            /** @var array<string, string> */
            public array $files = [];

            public function engineDirPath(): string
            {
                return '/opt/panelalpha/shared-hosting';
            }

            public function filesystem(): Filesystem
            {
                $engine = $this;

                return new class($engine) extends Filesystem
                {
                    public function __construct(private System $engine)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->engine->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->engine->files[$path];
                    }
                };
            }
        };
        $system->files = $files;

        return new EngineCertificate($system);
    }

    public function test_a_wildcard_pair_covers_a_project_under_it(): void
    {
        $pair = TestCertificates::selfSigned(['*.engine.example.test']);

        $covering = self::engine([self::CERT => "\n".$pair['cert']."\n", self::KEY => $pair['key']])
            ->covering('shop.engine.example.test');

        $this->assertNotNull($covering);
        $this->assertSame(trim($pair['cert']), $covering['certificate']);
        $this->assertSame(trim($pair['key']), $covering['key']);
        $this->assertSame(CertificateStatus::SELF_SIGNED, $covering['status']['status']);
        $this->assertTrue($covering['status']['covers_domain']);
    }

    public function test_a_name_the_certificate_does_not_cover_gets_nothing(): void
    {
        $pair = TestCertificates::selfSigned(['*.engine.example.test']);

        $this->assertNull(self::engine([self::CERT => $pair['cert'], self::KEY => $pair['key']])
            ->covering('a.b.engine.example.test'));
    }

    public function test_a_key_from_another_pair_covers_nothing(): void
    {
        $pair = TestCertificates::selfSigned(['shop.example.test']);
        $other = TestCertificates::selfSigned(['shop.example.test']);

        $this->assertNull(self::engine([self::CERT => $pair['cert'], self::KEY => $other['key']])
            ->covering('shop.example.test'));
    }

    public function test_a_certificate_out_of_date_covers_nothing(): void
    {
        $pair = TestCertificates::selfSigned(['shop.example.test'], 30);
        $engine = self::engine([self::CERT => $pair['cert'], self::KEY => $pair['key']]);

        $this->assertNull($engine->covering('shop.example.test', time() + 60 * 86400));
        $this->assertNull($engine->covering('shop.example.test', time() - 2 * 86400));
        $this->assertNotNull($engine->covering('shop.example.test', time()));
    }

    public function test_empty_or_unreadable_files_cover_nothing(): void
    {
        $pair = TestCertificates::selfSigned(['shop.example.test']);

        $this->assertNull(self::engine([self::CERT => $pair['cert']])->covering('shop.example.test'));
        $this->assertNull(self::engine([self::CERT => " \n", self::KEY => $pair['key']])->covering('shop.example.test'));
        $this->assertNull(self::engine([self::CERT => $pair['cert'], self::KEY => ''])->covering('shop.example.test'));
        $this->assertNull(self::engine([self::CERT => 'not a certificate', self::KEY => $pair['key']])->covering('shop.example.test'));
    }
}
