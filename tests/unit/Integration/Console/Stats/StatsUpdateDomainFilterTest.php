<?php

namespace Tests\Integration\Console\Stats;

use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\System;
use App\System\Services\Webserver;
use Tests\Integration\Integrations\Statistics\FakeStatistics;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `stats:update --project` and `--domain` narrow the domains whose logs are ingested, read from the database. */
class StatsUpdateDomainFilterTest extends TestCase
{
    use InMemoryDatabase;

    private FakeStatistics $stats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->stats = new FakeStatistics;
        $this->app->instance(Statistics::class, $this->stats);
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
        $this->app->instance(System::class, new RecordingSystem);

        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'alice.example.test');
        Domain::query()->create(['user_id' => $alice->id, 'domain' => 'shop.example.test', 'type' => 'addon', 'details' => ['aliases' => ['www.shop.example.test']]]);
        $this->makeMainDomain($this->makeUser('bob'), 'bob.example.test');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_every_domain_is_ingested_without_a_filter(): void
    {
        $this->artisan('stats:update')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['alice.example.test', 'shop.example.test', 'bob.example.test'], $this->stats->ingested);
    }

    public function test_project_narrows_to_its_domains(): void
    {
        $this->artisan('stats:update --project=alice')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['alice.example.test', 'shop.example.test'], $this->stats->ingested);
    }

    public function test_project_and_domain_narrow_to_one_domain(): void
    {
        $this->artisan('stats:update --project=alice --domain=shop.example.test')->assertExitCode(0);

        $this->assertSame(['shop.example.test'], $this->stats->ingested);
    }

    public function test_a_domain_of_another_project_matches_nothing(): void
    {
        $this->artisan('stats:update --project=bob --domain=shop.example.test')->assertExitCode(0);

        $this->assertSame([], $this->stats->ingested);
    }
}
