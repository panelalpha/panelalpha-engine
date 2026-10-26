<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * engine#786: core opens git connections from its own container, whose network
 * reaches the metadata service and the registries. The guard refuses a
 * repository on a private, loopback, link-local or reserved address -- IPv6
 * included, which the old IPv4-only check missed.
 */
class RepositoryAddressTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'ipv4 loopback' => ['https://127.0.0.1:5000/o/r.git'],
            'ipv4 private 10/8' => ['https://10.0.0.5/o/r.git'],
            'cloud metadata' => ['https://169.254.169.254/o/r.git'],
            'ipv6 loopback literal' => ['https://[::1]/o/r.git'],
            'ipv6 unique-local literal' => ['https://[fd00::1]/o/r.git'],
            'ipv6 mapped metadata' => ['https://[::ffff:169.254.169.254]/o/r.git'],
            'scp-style private' => ['git@10.0.0.5:o/r.git'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_repository_on_a_private_address_is_refused(string $repo): void
    {
        $this->assertTrue(RepositoryAddress::refused($repo), $repo);
        $this->assertSame('host_refused', RepositoryAddress::problem($repo)['code'] ?? null);
    }

    /** A host with only an AAAA record is refused, which IPv4-only missed. */
    public function test_a_host_whose_only_record_is_internal_is_refused(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['fd00::1']);

        $this->assertTrue(RepositoryAddress::refused('https://code.internal/o/r.git'));
    }

    /** One internal record among public ones is refused too (a rebind attempt). */
    public function test_a_host_with_a_mixed_answer_is_refused(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['203.0.113.7', '10.0.0.5']);

        $this->assertTrue(RepositoryAddress::refused('https://rebind.example/o/r.git'));
    }

    public function test_a_public_repository_is_allowed(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['140.82.121.4']);

        $this->assertFalse(RepositoryAddress::refused('https://github.com/owner/repo.git'));
        $this->assertFalse(RepositoryAddress::refused('git@github.com:owner/repo.git'));
    }

    /**
     * git and libcurl parse numeric IPv4 forms PHP's resolver does not, so a
     * host that does not resolve is not one the engine can check.
     *
     * @return array<string, array{string}>
     */
    public static function hostsPhpDoesNotResolve(): array
    {
        return [
            'hexadecimal loopback' => ['http://0x7f000001/x.git'],
            'decimal integer loopback' => ['http://2130706433/x.git'],
            'octal loopback' => ['http://0177.0.0.1/x.git'],
            'hexadecimal metadata' => ['http://0xa9fea9fe/x.git'],
            'a name nothing answers for' => ['https://unreachable.invalid/o/r.git'],
        ];
    }

    #[DataProvider('hostsPhpDoesNotResolve')]
    public function test_a_host_the_engine_cannot_resolve_is_refused(string $repo): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => []);

        $this->assertTrue(RepositoryAddress::refused($repo), $repo);
        $this->assertSame('host_refused', RepositoryAddress::problem($repo)['code'] ?? null);
    }

    public function test_a_bracketed_ipv6_scp_remote_yields_its_host_and_is_refused(): void
    {
        $this->assertSame('::1', RepositoryAddress::host('git@[::1]:x/y.git'));
        $this->assertTrue(RepositoryAddress::refused('git@[::1]:x/y.git'));
        $this->assertTrue(RepositoryAddress::refused('git@[fd00::1]:x/y.git'));
    }
}
