<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\SharedZones;
use PHPUnit\Framework\TestCase;

/**
 * What the engine refuses to ask an authority for, and what it merely reports.
 *
 * The line moved deliberately. Being on a zone the fleet shares used to be a
 * refusal; it is now a cost the operator is told about and gets to decide on.
 * What is still refused is a name no authority could issue for under any
 * circumstances — ordering one spends a failed authorization (Let's Encrypt
 * counts 5 per account per hostname per hour) to learn what was knowable
 * before asking.
 */
class SharedZonesTest extends TestCase
{
    /**
     * The zones every engine issues under. Still recognised — `system_info`
     * and `sites:base-domain` report the shared budget — but no longer a bar
     * to issuing.
     */
    public function test_a_shared_zone_is_recognised_but_not_refused(): void
    {
        foreach ([
            'app.203-0-113-45.sslip.io',
            'app.203-0-113-45.nip.io',
            'demo.203-0-113-45.panelalpha.direct',
            'panelalpha.direct',
        ] as $domain) {
            $this->assertTrue(SharedZones::covers($domain), $domain);
            $this->assertNull(
                SharedZones::ineligibleReason($domain),
                "{$domain} should be issuable: the shared budget is a cost, not a veto"
            );
        }
    }

    public function test_a_domain_the_operator_controls_is_neither_shared_nor_refused(): void
    {
        foreach (['app.example.com', 'invoicer.apps.acme.co.uk', 'shop.customer.test.org'] as $domain) {
            $this->assertFalse(SharedZones::covers($domain), $domain);
            $this->assertNull(SharedZones::ineligibleReason($domain), $domain);
        }
    }

    /**
     * A suffix match at a label boundary, not a substring one.
     */
    public function test_a_name_that_merely_contains_a_zone_is_not_on_it(): void
    {
        $this->assertFalse(SharedZones::covers('nip.io.example.com'));
        $this->assertFalse(SharedZones::covers('notsslip.io'));
        $this->assertTrue(SharedZones::covers('a.b.nip.io'));
    }

    /**
     * The one thing still refused, and the only reason it is: these fail
     * validation every time, so asking costs a failure and buys nothing.
     */
    public function test_names_no_authority_will_ever_certify_are_refused(): void
    {
        foreach (['', 'localhost', 'app', '203.0.113.45', 'app.local', 'site.test', '-bad.example.com'] as $domain) {
            $this->assertNotNull(SharedZones::ineligibleReason($domain), $domain ?: '(empty)');
        }
    }

    public function test_an_address_is_not_a_hostname(): void
    {
        $this->assertFalse(SharedZones::isPubliclyIssuable('203.0.113.45'));
        $this->assertFalse(SharedZones::isPubliclyIssuable('::1'));
        $this->assertTrue(SharedZones::isPubliclyIssuable('app.example.com'));
    }

    public function test_case_and_a_trailing_dot_do_not_change_the_answer(): void
    {
        $this->assertTrue(SharedZones::covers('APP.203-0-113-45.SSLIP.IO'));
        $this->assertTrue(SharedZones::covers('app.sslip.io.'));
    }

    public function test_the_refusal_names_the_domain_it_is_about(): void
    {
        $reason = SharedZones::ineligibleReason('app.local');

        $this->assertStringContainsString('app.local', (string) $reason);
        $this->assertStringContainsString('public hostname', (string) $reason);
    }
}
