<?php

namespace Tests\Support;

use App\Lib\Deploy\Health\CheckRegistry;

trait MarketRadarSourceRecipeFixtures
{
    private const URL = 'https://github.com/vvolv/market-radar';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
    }

    protected function tearDown(): void
    {
        CheckRegistry::flush();
        parent::tearDown();
    }
}
