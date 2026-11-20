<?php

namespace Tests\Integration\Usage;

use App\Integrations\Statistics\Statistics;
use App\Lib\Usage\ProjectBandwidth;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A project whose statistics cannot be read is skipped and logged; the others are still stored. */
class ProjectBandwidthUnreadableTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_an_unreadable_project_is_left_out_of_the_sample(): void
    {
        $this->makeMainDomain($this->makeUser('shop'), 'shop.example.test');
        $this->makeMainDomain($this->makeUser('blog'), 'blog.example.test');
        $statistics = Mockery::mock(Statistics::class);
        $statistics->shouldReceive('projectCalendarMonthBytes')->with(['shop.example.test'])->andThrow(new \RuntimeException('awstats data unreadable'));
        $statistics->shouldReceive('projectCalendarMonthBytes')->with(['blog.example.test'])->andReturn(2048);
        Log::spy();

        $this->assertSame(1, (new ProjectBandwidth($statistics))->sample());

        $this->assertSame(['blog'], array_keys(ProjectBandwidth::all()));
        $this->assertSame(2048, ProjectBandwidth::all()['blog']['used_bytes']);
        Log::shouldHaveReceived('warning')->with("Could not read this month's transfer of shop: awstats data unreadable");
    }
}
