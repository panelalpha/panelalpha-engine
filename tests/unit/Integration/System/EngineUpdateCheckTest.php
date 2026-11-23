<?php

namespace Tests\Integration\System;

use App\System\EngineUpdateCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\HubReleases;
use Tests\TestCase;

/**
 * Whether a newer release exists: the running version against the newest engine release the PanelAlpha Hub
 * publishes, the Hub's answer kept for an hour.
 */
class EngineUpdateCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['system.version' => '2.3.1', 'app.uid' => 'engine-uid-1']);
    }

    public function test_the_hub_names_the_newest_release(): void
    {
        HubReleases::fake(['2.4.0']);

        $check = (new EngineUpdateCheck)->check();

        $this->assertSame(['version' => '2.3.1'], $check['running']);
        $this->assertSame([
            'version' => '2.4.0',
            'published_at' => '2026-11-19',
            'url' => 'https://engine.panelalpha.com/changelog',
            'notes' => "## New Features\n\n- Something new.",
        ], $check['available']);
        $this->assertSame('update_available', $check['status']);
        $this->assertTrue($check['update_available']);
        $this->assertNull($check['reason']);
        $this->assertFalse($check['cached']);
        $this->assertEqualsWithDelta(time(), $check['checked_at'], 5);
    }

    public function test_the_hub_is_asked_once_over_https_with_the_engines_identity(): void
    {
        HubReleases::fake(['2.4.0']);

        (new EngineUpdateCheck)->check();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === HubReleases::URL
            && $request->header('Accept') === ['application/json']
            && $request->hasHeader('X-Engine-App-UID', 'engine-uid-1'));
        $this->assertSame(1, HubReleases::asked());
    }

    public function test_the_same_release_is_up_to_date_and_an_older_one_is_not_an_update(): void
    {
        $expected = [
            ['2.3.1', 'up_to_date'],
            ['v2.3.1', 'up_to_date'],
            ['2.3.0', 'older_available'],
            ['2.3.1-beta', 'older_available'],
            ['2.10.0', 'update_available'],
        ];
        HubReleases::fake(array_column($expected, 0));

        foreach ($expected as [$version, $status]) {
            $this->assertSame($status, (new EngineUpdateCheck)->check(true)['status'], $version);
        }
    }

    public function test_a_second_check_is_the_kept_answer(): void
    {
        HubReleases::fake(['2.4.0']);
        $first = (new EngineUpdateCheck)->check();

        $second = (new EngineUpdateCheck)->check();

        $this->assertSame(1, HubReleases::asked());
        $this->assertTrue($second['cached']);
        $this->assertSame($first['checked_at'], $second['checked_at']);
        $this->assertSame($first['available'], $second['available']);
    }

    public function test_the_kept_answer_is_compared_with_the_version_running_now(): void
    {
        HubReleases::fake(['2.4.0']);
        (new EngineUpdateCheck)->check();
        config(['system.version' => '2.4.0']);

        $check = (new EngineUpdateCheck)->check();

        $this->assertTrue($check['cached']);
        $this->assertSame('up_to_date', $check['status']);
        $this->assertSame(['version' => '2.4.0'], $check['running']);
    }

    public function test_refresh_asks_the_hub_again(): void
    {
        HubReleases::fake(['2.4.0', '2.3.1']);
        (new EngineUpdateCheck)->check();

        $check = (new EngineUpdateCheck)->check(true);

        $this->assertSame(2, HubReleases::asked());
        $this->assertSame('up_to_date', $check['status']);
        $this->assertFalse($check['cached']);
        $this->assertSame('up_to_date', (new EngineUpdateCheck)->check()['status']);
    }

    public function test_a_hub_that_fails_is_unknown_and_not_kept(): void
    {
        HubReleases::fake([null, '2.4.0']);

        $check = (new EngineUpdateCheck)->check();

        $this->assertSame('unknown', $check['status']);
        $this->assertNull($check['available']);
        $this->assertFalse($check['update_available']);
        $this->assertSame('The PanelAlpha Hub answered the release list with Server Error.', $check['reason']);
        $this->assertNull(Cache::get('engine-update-check'));
        $this->assertSame('update_available', (new EngineUpdateCheck)->check()['status']);
    }

    public function test_a_release_list_without_an_engine_release_is_unknown(): void
    {
        config(['hub.url' => HubReleases::HUB]);
        Http::preventStrayRequests();
        Http::fake([HubReleases::URL => Http::sequence()
            ->push(['data' => ['multi-server-business' => ['latest' => ['version' => '1.9.8.4']]]])
            ->push(['data' => ['engine' => ['latest' => ['version' => '']]]])
            ->push('<!DOCTYPE html><title>PanelAlpha Hub</title>', 200, ['Content-Type' => 'text/html'])]);
        $check = new EngineUpdateCheck;

        foreach ([1, 2, 3] as $ignored) {
            $answer = $check->check();
            $this->assertSame('unknown', $answer['status']);
            $this->assertSame("The PanelAlpha Hub's release list names no engine release.", $answer['reason']);
        }
    }

    public function test_a_hub_that_cannot_be_reached_is_unknown(): void
    {
        config(['hub.url' => HubReleases::HUB]);
        Http::preventStrayRequests();
        Http::fake([HubReleases::URL => Http::failedConnection()]);

        $check = (new EngineUpdateCheck)->check();

        $this->assertSame('unknown', $check['status']);
        $this->assertStringStartsWith('Could not reach PanelAlpha Connect at '.HubReleases::HUB, (string) $check['reason']);
    }

    public function test_a_hub_that_is_not_https_is_never_asked(): void
    {
        config(['hub.url' => 'http://hub.example.test']);
        Http::preventStrayRequests();
        Http::fake();

        $check = (new EngineUpdateCheck)->check();

        $this->assertSame('unknown', $check['status']);
        $this->assertSame('PANELALPHA_HUB must be an https URL to connect this engine (got http://hub.example.test).', $check['reason']);
        Http::assertNothingSent();
    }

    public function test_an_engine_that_declares_no_version_is_unknown(): void
    {
        HubReleases::fake(['2.4.0']);
        config(['system.version' => '']);

        $check = (new EngineUpdateCheck)->check();

        $this->assertSame('unknown', $check['status']);
        $this->assertNull($check['running']['version']);
        $this->assertSame('2.4.0', $check['available']['version'] ?? null);
        $this->assertSame('The running engine declares no version.', $check['reason']);
    }
}
