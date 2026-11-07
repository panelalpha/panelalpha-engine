<?php

namespace Tests\Unit\Mail;

use App\Lib\Mail\SpfCheck;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Pins check_host() results and reasons for the branches MailDnsRecordsTest does not reach, on a fake DNS. */
class SpfCheckTest extends TestCase
{
    private static function ip(): string
    {
        return TestNetwork::public(7);
    }

    /**
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $a
     * @param  array<string, list<string>>  $mx
     * @param  list<string>  $failing
     */
    private static function dns(array $txt, array $a = [], array $mx = [], array $failing = []): Closure
    {
        return static function (string $name, int $type) use ($txt, $a, $mx, $failing): array|false {
            if (in_array($name, $failing, true)) {
                return false;
            }

            return match ($type) {
                DNS_TXT => array_map(static fn (string $v): array => ['txt' => $v], $txt[$name] ?? []),
                DNS_A => array_map(static fn (string $ip): array => ['ip' => $ip], $a[$name] ?? []),
                DNS_MX => array_map(static fn (string $host): array => ['target' => $host], $mx[$name] ?? []),
                default => [],
            };
        };
    }

    /** @return array<string, array{0: array<string, list<string>>, 1: string, 2: string}> */
    public static function records(): array
    {
        $ip = self::ip();
        $chain = [];
        for ($i = 0; $i < 12; $i++) {
            $chain["d{$i}.test"] = ["v=spf1 include:d".($i + 1).'.test -all'];
        }

        return [
            'no record' => [[], 'none', 'example.test publishes no SPF record'],
            'two records' => [['example.test' => ['v=spf1 -all', 'v=spf1 ~all']], 'permerror', 'example.test publishes more than one SPF record, which receivers treat as an error'],
            'modifier skipped, fail on all' => [['example.test' => ['v=spf1 exp=explain.test -all']], 'fail', "example.test's SPF record matches {$ip} on -all"],
            'neutral qualifier' => [['example.test' => ['v=spf1 ?all']], 'neutral', "example.test's SPF record matches {$ip} on ?all"],
            'softfail' => [['example.test' => ['v=spf1 ~all']], 'softfail', "example.test's SPF record matches {$ip} on ~all"],
            'unusable term' => [['example.test' => ['v=spf1 bogus:x -all']], 'permerror', "example.test's SPF record has a term it cannot use: bogus:x"],
            'nothing matches' => [['example.test' => ['v=spf1 ip4:'.TestNetwork::net('PUBLIC_2')]], 'neutral', "nothing in example.test's SPF record matches {$ip}"],
            'ip4 match' => [['example.test' => ['v=spf1 ip4:'.TestNetwork::prefix($ip, 24).' -all']], 'pass', "example.test's SPF record matches {$ip} on +ip4:".TestNetwork::prefix($ip, 24)],
            'redirect' => [['example.test' => ['v=spf1 redirect=other.test.'], 'other.test' => ["v=spf1 ip4:{$ip} -all"]], 'pass', "other.test's SPF record matches {$ip} on +ip4:{$ip}"],
            'redirect to nothing' => [['example.test' => ['v=spf1 redirect=other.test']], 'permerror', 'other.test publishes no SPF record'],
            'include of nothing' => [['example.test' => ['v=spf1 include:other.test -all']], 'permerror', 'example.test includes other.test, which publishes no SPF record'],
            'include fails through' => [['example.test' => ['v=spf1 include:other.test ~all'], 'other.test' => ['v=spf1 -all']], 'softfail', "example.test's SPF record matches {$ip} on ~all"],
            'macro argument never matches' => [['example.test' => ['v=spf1 a:%{d}.test -all']], 'fail', "example.test's SPF record matches {$ip} on -all"],
            'ptr and exists are counted, never matched' => [['example.test' => ['v=spf1 ptr exists:x.test ?all']], 'neutral', "example.test's SPF record matches {$ip} on ?all"],
            'too many lookups' => [$chain + ['example.test' => ['v=spf1 include:d0.test -all']], 'permerror', "d9.test's SPF record needs more than 10 DNS lookups, which receivers treat as an error"],
        ];
    }

    /** @param  array<string, list<string>>  $txt */
    #[DataProvider('records')]
    public function test_check_host(array $txt, string $result, string $reason): void
    {
        $spf = new SpfCheck(self::dns($txt));

        $this->assertSame($result, $spf->resultFor('Example.test', self::ip()));
        $this->assertSame($reason, $spf->reason());
    }

    public function test_a_failed_lookup_is_a_temperror(): void
    {
        $spf = new SpfCheck(self::dns([], failing: ['example.test']));

        $this->assertSame('temperror', $spf->resultFor('example.test', self::ip()));
        $this->assertSame('the SPF lookup for example.test failed', $spf->reason());
    }

    public function test_a_and_mx_resolve_their_hosts(): void
    {
        $dns = self::dns(
            ['example.test' => ['v=spf1 a/24 mx:mail.test -all'], 'mx.test' => ['v=spf1 mx -all']],
            ['example.test' => [TestNetwork::public2(9)], 'mx1.mail.test' => [self::ip()], 'mx1.mx.test' => [TestNetwork::add(self::ip(), 1)]],
            ['mail.test' => ['mx1.mail.test'], 'mx.test' => ['mx1.mx.test']]
        );
        $spf = new SpfCheck($dns);

        $this->assertSame('pass', $spf->resultFor('example.test', self::ip()));
        $this->assertSame("example.test's SPF record matches ".self::ip().' on +mx:mail.test', $spf->reason());
        $this->assertSame('fail', $spf->resultFor('mx.test', self::ip()));
        $this->assertSame("mx.test's SPF record matches ".self::ip().' on -all', $spf->reason());
    }

    public function test_too_many_address_lookups_is_a_permerror(): void
    {
        $terms = implode(' ', array_map(static fn (int $i): string => "a:h{$i}.test", range(1, 11)));
        $spf = new SpfCheck(self::dns(['example.test' => ["v=spf1 {$terms} -all"]]));

        $this->assertSame('permerror', $spf->resultFor('example.test', self::ip()));
        $this->assertSame("example.test's SPF record needs more than 10 DNS lookups, which receivers treat as an error", $spf->reason());
    }

    public function test_too_many_ptr_lookups_is_a_permerror(): void
    {
        $spf = new SpfCheck(self::dns(['example.test' => ['v=spf1 '.str_repeat('ptr ', 11).'-all']]));

        $this->assertSame('permerror', $spf->resultFor('example.test', self::ip()));
    }

    public function test_a_redirect_past_the_lookup_limit_is_a_permerror(): void
    {
        $txt = ['example.test' => ['v=spf1 '.str_repeat('ptr ', 10).'redirect=other.test']];
        $spf = new SpfCheck(self::dns($txt));

        $this->assertSame('permerror', $spf->resultFor('example.test', self::ip()));
        $this->assertSame("example.test's SPF record needs more than 10 DNS lookups, which receivers treat as an error", $spf->reason());
    }
}
