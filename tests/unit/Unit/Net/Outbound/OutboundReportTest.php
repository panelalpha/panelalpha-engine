<?php

namespace Tests\Unit\Net\Outbound;

use App\Lib\Net\Outbound\OutboundLimits;
use App\Lib\Net\Outbound\OutboundReport;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** The root broker's outbound limits report as core reads it, and the two host actions core asks for. */
class OutboundReportTest extends TestCase
{
    private static function report(): string
    {
        return '{"active": true, "settings": {"EGRESS_BLOCK_SMTP": 1, "EGRESS_SCAN_BLOCK_MINUTES": 30, "x": "y"},'
            .' "blocks": [{"owner": "shop", "network": "tenants", "address": "'.TestNetwork::TENANT_ACCOUNT.'", "since": 1800000000, "until": 1800001800, "seconds_left": 1790},'
            .' {"owner": "broken", "network": "tenants", "address": "'.TestNetwork::add(TestNetwork::TENANT_ACCOUNT, 1).'", "since": "soon", "until": 1}],'
            .' "marks": [{"owner": "blog", "network": "build", "kind": "smtp", "address": "'.TestNetwork::container2(2).'", "since": 1800000010, "last": 1800000020},'
            .' {"owner": "blog", "network": "build", "kind": "other", "address": "'.TestNetwork::container2(2).'", "since": 1, "last": 2}]}';
    }

    public function test_a_report_keeps_its_well_formed_entries(): void
    {
        $report = OutboundReport::fromJson(self::report()."\n");

        $this->assertTrue($report->active);
        $this->assertSame(['EGRESS_BLOCK_SMTP' => 1, 'EGRESS_SCAN_BLOCK_MINUTES' => 30], $report->settings);
        $this->assertSame([['owner' => 'shop', 'network' => 'tenants', 'address' => TestNetwork::TENANT_ACCOUNT, 'since' => 1800000000, 'until' => 1800001800]], $report->blocks);
        $this->assertSame([['owner' => 'blog', 'network' => 'build', 'kind' => 'smtp', 'address' => TestNetwork::container2(2), 'since' => 1800000010, 'last' => 1800000020]], $report->marks);
    }

    public function test_an_answer_that_is_no_report_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        OutboundReport::fromJson('pa-root: refused');
    }

    public function test_the_report_and_the_unblock_are_the_hosts_two_actions(): void
    {
        $asked = [];
        $limits = new OutboundLimits(function (array $argv) use (&$asked): string {
            $asked[] = $argv;

            return str_contains(implode(' ', $argv), 'egressUnblock')
                ? '{"owner": "shop", "addresses": ["'.TestNetwork::TENANT_ACCOUNT.'", 7]}'
                : self::report();
        });

        $this->assertTrue($limits->report()->active);
        $this->assertSame([TestNetwork::TENANT_ACCOUNT], $limits->unblock('shop'));
        $this->assertContains('host.egressReport', $asked[0]);
        $this->assertContains('host.egressUnblock', $asked[1]);
        $this->assertSame('shop', $asked[1][array_key_last($asked[1])]);
    }

    public function test_an_owner_that_is_not_a_name_never_reaches_the_broker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OutboundLimits(static fn (): string => ''))->unblock('../etc');
    }
}
