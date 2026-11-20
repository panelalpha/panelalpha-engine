<?php

namespace Tests\Unit\Mail;

use App\Lib\Mail\MailDnsRecords;
use Closure;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** The answers check() gives when DNS fails, says too much, or the engine lacks what it needs to judge. */
class MailDnsRecordsEdgesTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $txt
     * @param  list<string>  $failing
     */
    private function dns(array $txt = [], array $failing = []): Closure
    {
        return static function (string $name, int $type) use ($txt, $failing): array|false {
            if (in_array($name, $failing, true)) {
                return false;
            }

            return $type === DNS_TXT
                ? array_map(static fn (string $v): array => ['type' => 'TXT', 'txt' => $v, 'entries' => [$v]], $txt[$name] ?? [])
                : [];
        };
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function byType(array $records): array
    {
        return array_column($records, null, 'type');
    }

    public function test_a_host_with_no_public_address_cannot_be_judged(): void
    {
        $records = $this->byType((new MailDnsRecords($this->dns(['shop.example' => ['v=spf1 a ~all']])))->check('shop.example', '', []));

        $this->assertStringContainsString('no public address on record, so the engine cannot say', $records['spf']['note']);
        $this->assertSame('unknown', $records['spf']['status']);
        $this->assertSame(['v=spf1 a ~all'], $records['spf']['found']);
        $this->assertSame('This host has no public address on record to check the record against.', $records['spf']['problem']);
    }

    public function test_a_failed_lookup_inside_the_spf_record_is_unknown(): void
    {
        $dns = $this->dns(['shop.example' => ['v=spf1 include:broken.example ~all']], ['broken.example']);

        $spf = $this->byType((new MailDnsRecords($dns))->check('shop.example', '', [TestNetwork::public(10)]))['spf'];

        $this->assertSame('unknown', $spf['status']);
        $this->assertSame('The SPF lookup for broken.example failed.', $spf['problem']);
    }

    public function test_a_generic_smtp_relay_cannot_be_checked_for_its_include(): void
    {
        $spf = $this->byType((new MailDnsRecords($this->dns(['shop.example' => ['v=spf1 include:relay.example ~all']])))->check('shop.example', 'smtp', []))['spf'];

        $this->assertSame('unknown', $spf['status']);
        $this->assertStringStartsWith('The engine cannot tell which servers your SMTP relay sends from', (string) $spf['problem']);
    }

    public function test_a_dkim_lookup_that_fails_or_finds_nothing_is_reported(): void
    {
        $failed = $this->byType((new MailDnsRecords($this->dns([], ['s1._domainkey.shop.example'])))->check('shop.example', 'sendgrid', [], 's1'))['dkim'];
        $this->assertSame('unknown', $failed['status']);
        $this->assertSame('The TXT lookup for s1._domainkey.shop.example failed.', $failed['problem']);

        $empty = $this->byType((new MailDnsRecords($this->dns()))->check('shop.example', 'sendgrid', [], 's1'))['dkim'];
        $this->assertSame('missing', $empty['status']);
        $this->assertSame('s1._domainkey.shop.example publishes no DKIM key.', $empty['problem']);
    }

    public function test_two_dmarc_records_are_wrong(): void
    {
        $dns = $this->dns(['_dmarc.shop.example' => ['v=DMARC1; p=none', 'v=DMARC1; p=reject']]);

        $dmarc = $this->byType((new MailDnsRecords($dns))->check('shop.example', '', []))['dmarc'];

        $this->assertSame('wrong', $dmarc['status']);
        $this->assertSame(['v=DMARC1; p=none', 'v=DMARC1; p=reject'], $dmarc['found']);
        $this->assertSame('_dmarc.shop.example publishes more than one DMARC record, so receivers ignore them all.', $dmarc['problem']);
    }
}
