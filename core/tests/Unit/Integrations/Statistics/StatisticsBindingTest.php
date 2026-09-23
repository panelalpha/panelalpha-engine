<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\Statistics\Awstats;
use App\Integrations\Statistics\Statistics;
use Tests\TestCase;

class StatisticsBindingTest extends TestCase
{
    public function test_container_binds_statistics_as_awstats_singleton(): void
    {
        $first = $this->app->make(Statistics::class);

        $this->assertInstanceOf(Awstats::class, $first);
        $this->assertSame($first, $this->app->make(Statistics::class));
    }

    public function test_container_accepts_a_replacement_adapter(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);

        $this->assertSame($fake, $this->app->make(Statistics::class));
    }
}
