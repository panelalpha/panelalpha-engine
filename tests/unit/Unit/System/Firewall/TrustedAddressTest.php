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

    public function test_a_comment_is_one_line_whatever_letters_or_control_characters_it_holds(): void
    {
        // ą is C4 85 in UTF-8: \R would split it in half. A vertical tab or form feed is not a line break either.
        $list = TrustedAddress::parseList("203.0.113.7 # biuro ąę Å Ņ\n198.51.100.7 # office\x0b10.0.0.0/8\x0cend\n");

        $this->assertSame([
            ['address' => '203.0.113.7', 'comment' => 'biuro ąę Å Ņ'],
            ['address' => '198.51.100.7', 'comment' => "office\x0b10.0.0.0/8\x0cend"],
        ], array_map(fn (TrustedAddress $a): array => ['address' => $a->address, 'comment' => $a->comment], $list));
    }

    public function test_the_id_follows_the_address_as_stored(): void
    {
        $this->assertSame((new TrustedAddress('203.0.113.7'))->id(), (new TrustedAddress('203.0.113.7/32', 'x'))->id());
        $this->assertSame(['id' => (new TrustedAddress('203.0.113.7'))->id(), 'address' => '203.0.113.7', 'comment' => 'x'], (new TrustedAddress('203.0.113.7', 'x'))->toArray());
    }
}
