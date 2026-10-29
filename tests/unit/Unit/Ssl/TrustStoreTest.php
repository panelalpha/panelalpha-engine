<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\TrustStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The difference between "signed by somebody" and "signed by somebody anyone
 * trusts", which `status: trusted` used to miss.
 */
class TrustStoreTest extends TestCase
{
    private const IN_DATE = ['not_before' => 1, 'not_after' => 4102444800];

    public function test_a_checked_failure_is_not_trusted(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => '(STAGING) Pretend Pear X1',
            'domains' => ['shop.example.com'],
            'chain_trusted' => false,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::UNTRUSTED_ISSUER, $status['status']);
        $this->assertFalse($status['self_signed'], 'it was signed by an authority, just not one we trust');
        $this->assertFalse($status['chain_trusted']);
    }

    /**
     * "Could not check" must keep reading as it always did: a host with no CA
     * bundle would otherwise report every real certificate as suspect.
     */
    public function test_an_unchecked_certificate_still_reads_as_trusted(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => 'R11',
            'domains' => ['shop.example.com'],
            'chain_trusted' => null,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::TRUSTED, $status['status']);
        $this->assertNull($status['chain_trusted']);
    }

    public function test_self_signed_still_wins_over_the_chain_verdict(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => 'shop.example.com',
            'domains' => ['shop.example.com'],
            'chain_trusted' => false,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::SELF_SIGNED, $status['status']);
    }

    /** A leaf served without its chain used to read as "could not check", and so as trusted. */
    public function test_a_lone_leaf_from_an_unknown_authority_is_not_trusted(): void
    {
        if (TrustStore::defaultBundle() === null) {
            $this->markTestSkipped('no CA bundle on this image');
        }

        $leaf = TestCertificates::signedBy(['shop.example.com'], TestCertificates::authority());

        $this->assertFalse(TrustStore::verifies($leaf));
    }

    public function test_a_leaf_with_its_intermediate_verifies_against_the_root(): void
    {
        [$chain, $rootBundle] = $this->chainAndRootBundle();

        try {
            $this->assertTrue(TrustStore::verifies($chain, $rootBundle));
        } finally {
            @unlink($rootBundle);
        }
    }

    /**
     * Without the intermediates the leaf alone would read as untrusted; an
     * unwritable temp directory is "could not check".
     */
    public function test_intermediates_that_cannot_be_written_out_read_as_could_not_check(): void
    {
        [$chain, $rootBundle] = $this->chainAndRootBundle();

        try {
            $this->assertSame('NULL', $this->verifiesWithUnwritableTempDir($chain, $rootBundle));
        } finally {
            @unlink($rootBundle);
        }
    }

    public function test_nothing_is_not_a_certificate(): void
    {
        $this->assertNull(TrustStore::verifies(''));
        $this->assertNull(TrustStore::verifies('not a pem'));
    }

    /** @return array{string, string} the leaf followed by its intermediate, and a bundle file holding only the root */
    private function chainAndRootBundle(): array
    {
        $root = TestCertificates::authority();
        $intermediate = TestCertificates::intermediate($root);
        $leaf = TestCertificates::signedBy(['shop.example.com'], $intermediate);
        $rootBundle = (string) tempnam(sys_get_temp_dir(), 'pa-root-');
        file_put_contents($rootBundle, $root['cert']);

        return [$leaf.$intermediate['cert'], $rootBundle];
    }

    private function verifiesWithUnwritableTempDir(string $chain, string $rootBundle): string
    {
        $script = 'require $argv[1]; echo var_export(App\Lib\Ssl\TrustStore::verifies(stream_get_contents(STDIN), $argv[2]), true);';
        $process = new Process(
            [PHP_BINARY, '-d', 'sys_temp_dir=/nonexistent-pa-tmp', '-d', 'display_errors=stderr', '-r', $script, dirname(__DIR__, 4).'/core/vendor/autoload.php', $rootBundle],
            null,
            ['TMPDIR' => '/nonexistent-pa-tmp'],
            $chain,
        );
        $process->mustRun();

        return trim($process->getOutput());
    }
}
