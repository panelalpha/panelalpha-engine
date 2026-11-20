<?php

namespace Tests\Support;

use App\Lib\Http\OutboundUrl;

trait OutboundUrlFixtures
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }
}
