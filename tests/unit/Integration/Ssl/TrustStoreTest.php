<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\TrustStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The difference between "signed by somebody" and "signed by somebody anyone
 * trusts", which `status: trusted` used to miss.
 */
class TrustStoreTest extends TestCase
{
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
