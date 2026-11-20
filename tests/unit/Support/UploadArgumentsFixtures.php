<?php

namespace Tests\Support;

use App\Lib\Http\OutboundUrl;

trait UploadArgumentsFixtures
{
    /** @var array<int, string> */
    private array $cleanup = [];

    /** The URL's address is checked first; example.com answers a fixed public address, not the test host's DNS. */
    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'example.com' ? [TestNetwork::routable(34)] : []);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }
}
