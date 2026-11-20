<?php

namespace Tests\Unit\Http;

use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A redirect's Location resolved against the URL that answered it, and a non-address always refused. */
class OutboundUrlAbsoluteTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> */
    public static function locations(): array
    {
        return [
            'absolute' => ['https://a.example.com/x', 'HTTP://b.example.com/y', 'HTTP://b.example.com/y'],
            'root relative' => ['https://a.example.com:8443/shop/cart', '/login', 'https://a.example.com:8443/login'],
            'path relative' => ['https://a.example.com/shop/cart', 'checkout', 'https://a.example.com/shop/checkout'],
            'no path in the base' => ['http://a.example.com', 'next', 'http://a.example.com/next'],
            'a base without a host' => ['not a url', 'next', 'next'],
        ];
    }

    #[DataProvider('locations')]
    public function test_a_location_is_resolved_against_its_base(string $base, string $location, string $expected): void
    {
        $this->assertSame($expected, OutboundUrl::absoluteUrl($base, $location));
    }

    public function test_something_that_is_not_an_address_is_refused(): void
    {
        OutboundUrl::setPolicyForTests(false, []);

        $this->assertTrue(OutboundUrl::refusesAddress('localhost'));
        $this->assertTrue(OutboundUrl::refusesAddress('[not-an-ip]'));
    }
}
