<?php

namespace Tests\Unit\System\Firewall;

use App\System\Firewall\TrustedAddress;
use PHPUnit\Framework\TestCase;

class TrustedAddressTest extends TestCase
{
    public function test_the_list_file_is_read_as_the_migration_and_the_api_write_it(): void
    {
        $list = TrustedAddress::parseList(<<<'LIST'
            # written by the engine
            10.10.0.1
            198.51.100.7 # office # second floor

            2001:0db8:0:0::1/128   #  vpn
            not-an-address # skipped
            198.51.100.7
            LIST);

        $this->assertSame([
            ['address' => '10.10.0.1', 'comment' => null],
            ['address' => '198.51.100.7', 'comment' => 'office # second floor'],
            ['address' => '2001:db8::1', 'comment' => 'vpn'],
        ], array_map(fn (TrustedAddress $a): array => ['address' => $a->address, 'comment' => $a->comment], $list));
        $this->assertSame("10.10.0.1\n198.51.100.7 # office # second floor\n2001:db8::1 # vpn\n", TrustedAddress::formatList($list));
    }

    public function test_the_id_follows_the_address_as_stored(): void
    {
        $this->assertSame((new TrustedAddress('203.0.113.7'))->id(), (new TrustedAddress('203.0.113.7/32', 'x'))->id());
        $this->assertTrue((new TrustedAddress('203.0.113.7'))->isSingle());
        $this->assertFalse((new TrustedAddress('203.0.113.0/24'))->isSingle());
        $this->assertSame(['id' => (new TrustedAddress('203.0.113.7'))->id(), 'address' => '203.0.113.7', 'comment' => 'x'], (new TrustedAddress('203.0.113.7', 'x'))->toArray());
    }
}
