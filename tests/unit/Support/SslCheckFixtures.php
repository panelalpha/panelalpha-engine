<?php

namespace Tests\Support;

use App\Lib\Http\OutboundUrl;
use App\Lib\Ssl\SslCheck;
use App\Lib\Ssl\TlsHandshake;

trait SslCheckFixtures
{
    private const DOMAIN = 'shop.example.com';

    /** @var list<array{address: string, name: string}> */
    private array $dialled = [];

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setPolicyForTests(false, []);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $resolved
     * @param  array<string, string>  $presented  address => leaf PEM; an address not listed refuses the connection
     * @param  ?list<string>  $expected
     * @return array<string, mixed>
     */
    private function check(array $resolved, array $presented, ?string $installed, ?array $expected = null, ?int $now = null): array
    {
        $this->resolveTo($resolved);

        return (new SslCheck($this->handshake($presented), $now))
            ->check(self::DOMAIN, $expected ?? [TestNetwork::public(45)], TestNetwork::private(5), $installed);
    }

    /**
     * @param  list<string>  $addresses
     */
    private function resolveTo(array $addresses): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === self::DOMAIN ? $addresses : []);
    }

    /**
     * @param  array<string, string>  $presented
     * @param  list<string>  $intermediates
     */
    private function handshake(array $presented, array $intermediates = []): TlsHandshake
    {
        $dialled = &$this->dialled;

        return new class($presented, $intermediates, $dialled) extends TlsHandshake
        {
            /**
             * @param  array<string, string>  $presented
             * @param  list<string>  $intermediates
             * @param  list<array{address: string, name: string}>  $dialled
             */
            public function __construct(private array $presented, private array $intermediates, private array &$dialled) {}

            public function presented(string $address, string $serverName, int $port = 443): array
            {
                $this->dialled[] = ['address' => $address, 'name' => $serverName];
                $leaf = $this->presented[$address] ?? null;

                return $leaf === null
                    ? ['leaf' => null, 'intermediates' => [], 'error' => 'Connection refused']
                    : ['leaf' => $leaf, 'intermediates' => $this->intermediates, 'error' => null];
            }
        };
    }
}
