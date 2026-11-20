<?php

namespace Tests\Unit\System;

use App\System\Network;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Two spellings of one address are one address; a name that is not an address compares as text, case aside. */
class NetworkSameAddressTest extends TestCase
{
    public function test_names_that_are_not_addresses_compare_case_insensitively(): void
    {
        $this->assertTrue(Network::sameAddress('Shop.Example.Test', 'shop.example.test'));
        $this->assertFalse(Network::sameAddress('shop.example.test', TestNetwork::public(7)));
    }

    public function test_addresses_compare_by_their_binary_form(): void
    {
        $this->assertTrue(Network::sameAddress(TestNetwork::public(7), TestNetwork::public(7)));
        $this->assertFalse(Network::sameAddress(TestNetwork::public(7), TestNetwork::public(8)));
    }
}
