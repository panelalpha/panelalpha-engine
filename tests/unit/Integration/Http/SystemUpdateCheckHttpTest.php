<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeUpdateCheckSystem;
use Tests\Support\HubReleases;
use Tests\TestCase;

/** GET /system/update answers the running release against the newest one the Hub publishes, and changes nothing. */
class SystemUpdateCheckHttpTest extends TestCase
{
    private FakeUpdateCheckSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(Authenticate::class);
        config(['system.version' => '2.3.1']);
        $this->system = new FakeUpdateCheckSystem;
        $this->app->instance(System::class, $this->system);
    }

    public function test_the_comparison_is_answered(): void
    {
        HubReleases::fake(['2.4.0']);

        $this->getJson('/api/system/update')
            ->assertOk()
            ->assertJsonPath('data.running.version', '2.3.1')
            ->assertJsonPath('data.available.version', '2.4.0')
            ->assertJsonPath('data.available.published_at', '2026-11-19')
            ->assertJsonPath('data.available.url', 'https://engine.panelalpha.com/changelog')
            ->assertJsonPath('data.status', 'update_available')
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.cached', false);
        $this->assertSame(0, $this->system->started);
    }

    public function test_refresh_asks_the_hub_again(): void
    {
        HubReleases::fake(['2.4.0', '2.3.1']);

        $this->getJson('/api/system/update')->assertJsonPath('data.status', 'update_available');
        $this->getJson('/api/system/update')->assertJsonPath('data.cached', true);
        $this->getJson('/api/system/update?refresh=1')
            ->assertJsonPath('data.status', 'up_to_date')
            ->assertJsonPath('data.cached', false);
        $this->assertSame(2, HubReleases::asked());
    }

    public function test_a_refresh_that_is_not_a_boolean_is_refused(): void
    {
        HubReleases::fake([]);

        $this->getJson('/api/system/update?refresh=soon')->assertStatus(422)->assertJsonValidationErrors('refresh');
        $this->assertSame(0, HubReleases::asked());
    }

    public function test_a_hub_that_fails_is_unknown_with_the_reason(): void
    {
        HubReleases::fake([null]);

        $this->getJson('/api/system/update')
            ->assertOk()
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.available', null)
            ->assertJsonPath('data.reason', 'The PanelAlpha Hub answered the release list with Server Error.');
    }
}
