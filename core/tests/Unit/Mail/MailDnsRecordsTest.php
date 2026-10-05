<?php

namespace Tests\Unit\Mail;

use App\Lib\Mail\MailDnsRecords;
use App\Lib\Mail\SpfCheck;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * "Why does my site's mail go to spam" had no answer from the engine: it knew
 * nothing about the records a sending domain needs. These run against a fake
 * DNS, in dns_get_record()'s shape.
 */
class MailDnsRecordsTest extends TestCase
{
    /**
     * @param array<string, list<string>> $txt
     * @param array<string, list<array{0: int, 1: string}>> $mx
     * @param array<string, list<string>> $a
     * @param list<string> $failing names whose lookup fails
     */
    private function dns(array $txt = [], array $mx = [], array $a = [], array $failing = []): Closure
    {
        return static function (string $name, int $type) use ($txt, $mx, $a, $failing): array|false {
            if (in_array($name, $failing, true)) {
                return false;
            }

            return match ($type) {
                DNS_TXT => array_map(static fn (string $v): array => ['type' => 'TXT', 'txt' => $v, 'entries' => [$v]], $txt[$name] ?? []),
                DNS_MX => array_map(static fn (array $m): array => ['type' => 'MX', 'pri' => $m[0], 'target' => $m[1]], $mx[$name] ?? []),
                DNS_A => array_map(static fn (string $ip): array => ['type' => 'A', 'ip' => $ip], $a[$name] ?? []),
                default => [],
            };
        };
    }

    /** @param list<array<string, mixed>> $records */
    private function byType(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $out[$record['type']] = $record;
        }

        return $out;
    }

    public function test_direct_sending_asks_for_the_host_address_and_says_nothing_signs(): void
    {
        $records = $this->byType((new MailDnsRecords($this->dns()))->expected('shop.example', '', ['203.0.113.10', '2001:db8::10']));

        $this->assertSame(['spf', 'dkim', 'dmarc', 'mx'], array_keys($records));
        $this->assertSame('v=spf1 ip4:203.0.113.10 ip6:2001:db8::10 ~all', $records['spf']['expected']);
        $this->assertSame('shop.example', $records['spf']['host']);
        $this->assertNull($records['dkim']['expected']);
        $this->assertStringContainsString('does not sign', $records['dkim']['note']);
        $this->assertSame('_dmarc.shop.example', $records['dmarc']['host']);
        $this->assertSame('v=DMARC1; p=none', $records['dmarc']['expected']);
        $this->assertArrayNotHasKey('status', $records['spf'], 'nothing is looked up unless asked');
    }

    public function test_a_relay_asks_for_its_include_and_its_dkim_selector(): void
    {
        $records = $this->byType((new MailDnsRecords($this->dns()))->expected('shop.example', 'sendgrid', [], 's1'));

        $this->assertSame('v=spf1 include:sendgrid.net ~all', $records['spf']['expected']);
        $this->assertSame('s1._domainkey.shop.example', $records['dkim']['host']);

        $smtp = $this->byType((new MailDnsRecords($this->dns()))->expected('shop.example', 'smtp', []));
        $this->assertNull($smtp['spf']['expected']);
        $this->assertNull($smtp['dkim']['host']);
    }

    public function test_a_sender_domain_moves_the_records_to_it(): void
    {
        $this->assertSame('mail.host.example', MailDnsRecords::sendingDomain('Shop.Example', ' Mail.Host.Example '));
        $this->assertSame('shop.example', MailDnsRecords::sendingDomain('Shop.Example', ''));
    }

    public function test_relay_and_host_addresses(): void
    {
        $this->assertSame('', MailDnsRecords::relay(''));
        $this->assertSame('', MailDnsRecords::relay('postfix'));
        $this->assertSame('amazon_ses', MailDnsRecords::relay('amazon_ses'));

        $this->assertSame(['203.0.113.10'], MailDnsRecords::hostAddresses('10.0.0.5', null, ['10.0.0.5' => '203.0.113.10']));
        $this->assertSame([], MailDnsRecords::hostAddresses('10.0.0.5', '', []));
        $this->assertSame(['198.51.100.7', '2a01:4f8::1'], MailDnsRecords::hostAddresses('198.51.100.7', '2a01:4f8::1', []));
    }

    public function test_a_domain_set_up_for_direct_sending_checks_ok(): void
    {
        $dns = $this->dns(
            txt: [
                'shop.example' => ['google-site-verification=x', 'v=spf1 include:_spf.shop.example ~all'],
                '_spf.shop.example' => ['v=spf1 ip4:203.0.113.0/24 -all'],
                '_dmarc.shop.example' => ['v=DMARC1; p=quarantine; rua=mailto:d@shop.example'],
            ],
            mx: ['shop.example' => [[10, 'mx.mailbox.example']]],
        );

        $records = $this->byType((new MailDnsRecords($dns))->check('shop.example', '', ['203.0.113.10']));

        $this->assertSame('ok', $records['spf']['status'], (string) $records['spf']['problem']);
        $this->assertSame(['v=spf1 include:_spf.shop.example ~all'], $records['spf']['found']);
        $this->assertSame('ok', $records['dmarc']['status']);
        $this->assertSame('ok', $records['mx']['status']);
        $this->assertSame(['10 mx.mailbox.example'], $records['mx']['found']);
        // Nothing on this host signs, whatever DNS says.
        $this->assertSame('missing', $records['dkim']['status']);
    }

    public function test_what_is_missing_and_wrong_is_reported(): void
    {
        $dns = $this->dns(txt: ['shop.example' => ['v=spf1 ip4:198.51.100.1 -all']]);

        $records = $this->byType((new MailDnsRecords($dns))->check('shop.example', '', ['203.0.113.10']));

        $this->assertSame('wrong', $records['spf']['status']);
        $this->assertStringContainsString('203.0.113.10', (string) $records['spf']['problem']);
        $this->assertStringContainsString('fail', (string) $records['spf']['problem']);
        $this->assertSame('missing', $records['dmarc']['status']);
        $this->assertSame('missing', $records['mx']['status']);

        $two = $this->byType((new MailDnsRecords($this->dns(txt: ['shop.example' => ['v=spf1 a ~all', 'v=spf1 mx ~all']])))
            ->check('shop.example', '', ['203.0.113.10']));
        $this->assertSame('wrong', $two['spf']['status']);
        $this->assertStringContainsString('more than one', (string) $two['spf']['problem']);

        $none = $this->byType((new MailDnsRecords($this->dns()))->check('shop.example', '', ['203.0.113.10']));
        $this->assertSame('missing', $none['spf']['status']);
    }

    public function test_a_relay_include_is_found_through_nested_includes(): void
    {
        $dns = $this->dns(
            txt: [
                'shop.example' => ['v=spf1 include:_spf.corp.example ~all'],
                '_spf.corp.example' => ['v=spf1 include:sendgrid.net -all'],
                's1._domainkey.shop.example' => ['k=rsa; p=MIIBIjANBgkq'],
                '_dmarc.shop.example' => ['v=DMARC1; p=reject'],
            ],
        );
        $records = $this->byType((new MailDnsRecords($dns))->check('shop.example', 'sendgrid', [], 's1'));

        $this->assertSame('ok', $records['spf']['status']);
        $this->assertSame('ok', $records['dkim']['status']);
        $this->assertSame('ok', $records['dmarc']['status']);

        $sub = $this->byType((new MailDnsRecords($dns))->check('news.shop.example', 'sendgrid', []));
        $this->assertSame('ok', $sub['dmarc']['status'], 'the parent domain policy covers a subdomain');
        $this->assertSame(['v=DMARC1; p=reject'], $sub['dmarc']['found']);

        $wrong = $this->byType((new MailDnsRecords($this->dns(txt: ['shop.example' => ['v=spf1 include:mailgun.org ~all']])))
            ->check('shop.example', 'sendgrid', []));
        $this->assertSame('wrong', $wrong['spf']['status']);
        $this->assertSame('unknown', $wrong['dkim']['status'], 'no selector, nothing to look up');
    }

    public function test_a_revoked_dkim_key_and_a_null_mx_are_wrong(): void
    {
        $dns = $this->dns(
            txt: ['s1._domainkey.shop.example' => ['v=DKIM1; p=']],
            mx: ['shop.example' => [[0, '']]],
        );
        $records = $this->byType((new MailDnsRecords($dns))->check('shop.example', 'mailchannels', [], 's1'));

        $this->assertSame('wrong', $records['dkim']['status']);
        $this->assertSame('wrong', $records['mx']['status']);
    }

    public function test_a_failed_lookup_is_unknown_not_missing(): void
    {
        $dns = $this->dns(failing: ['shop.example', '_dmarc.shop.example']);
        $records = $this->byType((new MailDnsRecords($dns))->check('shop.example', '', ['203.0.113.10']));

        $this->assertSame('unknown', $records['spf']['status']);
        $this->assertSame('unknown', $records['dmarc']['status']);
        $this->assertSame('unknown', $records['mx']['status']);
    }

    public function test_spf_evaluation(): void
    {
        $spf = new SpfCheck($this->dns(
            txt: [
                'a.example' => ['v=spf1 a mx:mail.a.example/24 -all'],
                'b.example' => ['v=spf1 redirect=a.example'],
                'c.example' => ['v=spf1 ~ip4:203.0.113.10 +all'],
                'd.example' => ['v=spf1 include:missing.example -all'],
                'e.example' => ['v=spf1 ip6:2001:db8::/32 -all'],
                'loop.example' => ['v=spf1 include:loop.example -all'],
                'f.example' => ['v=spf1 foo:bar -all'],
            ],
            mx: ['mail.a.example' => [[10, 'mx1.a.example']]],
            a: ['a.example' => ['198.51.100.1'], 'mx1.a.example' => ['203.0.113.200']],
        ));

        $this->assertSame('pass', $spf->resultFor('a.example', '198.51.100.1'));
        $this->assertSame('pass', $spf->resultFor('a.example', '203.0.113.10'), 'mx with a /24');
        $this->assertSame('fail', $spf->resultFor('a.example', '192.0.2.1'));
        $this->assertSame('pass', $spf->resultFor('b.example', '198.51.100.1'));
        $this->assertSame('softfail', $spf->resultFor('c.example', '203.0.113.10'), 'the first match decides');
        $this->assertSame('permerror', $spf->resultFor('d.example', '203.0.113.10'));
        $this->assertSame('pass', $spf->resultFor('e.example', '2001:db8::10'));
        $this->assertSame('fail', $spf->resultFor('e.example', '203.0.113.10'));
        $this->assertSame('permerror', $spf->resultFor('loop.example', '203.0.113.10'));
        $this->assertSame('permerror', $spf->resultFor('f.example', '203.0.113.10'));
        $this->assertSame('none', $spf->resultFor('nothing.example', '203.0.113.10'));
    }

    public function test_cidr_matching(): void
    {
        $this->assertTrue(SpfCheck::inCidr('203.0.113.10', '203.0.113.0', '24'));
        $this->assertTrue(SpfCheck::inCidr('203.0.113.10', '203.0.113.10', ''));
        $this->assertFalse(SpfCheck::inCidr('203.0.113.10', '203.0.112.0', '24'));
        $this->assertTrue(SpfCheck::inCidr('203.0.113.10', '203.0.112.0', '23'));
        $this->assertTrue(SpfCheck::inCidr('2001:db8::10', '2001:db8::', '32'));
        $this->assertFalse(SpfCheck::inCidr('203.0.113.10', '2001:db8::', '32'));
    }
}
