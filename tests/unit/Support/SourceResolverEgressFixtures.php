<?php

namespace Tests\Support;

use App\Lib\Http\OutboundUrl;

trait SourceResolverEgressFixtures
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }
}
