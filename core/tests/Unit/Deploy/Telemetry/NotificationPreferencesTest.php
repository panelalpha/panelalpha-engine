<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where notification preferences are POSTed, and that the class that
 * builds that URL still exists.
 *
 * `telemetry:ship` calls {@see NotificationPreferences::sync()} after a
 * successful drain. Unchanged prefs are skipped (fingerprint) so ship does
 * not spam monitoring every five minutes.
 */
class NotificationPreferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Setting::setRuntimeSettings([
            'email' => 'ops@example.test',
            'cert_domain' => 'engine.example.test',
            'license_key' => 'TEST-KEY',
            NotificationPreferences::SETTING_TELEMETRY_ENABLED => '1',
            // Present so Setting::set updates runtime (not DB) in unit tests.
            NotificationPreferences::SETTING_PREFS_HASH => '',
        ]);
        config([
            'monitoring.url' => 'https://monitoring.test',
            'hub.url' => 'https://hub.example.test',
            'telemetry.reports_path' => Telemetry::EVENTS_PATH,
            'telemetry.timeout' => 5,
            'telemetry.token' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_sync_posts_preferences_at_the_telemetry_endpoint(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        $result = NotificationPreferences::sync(true);

        $this->assertTrue($result['ok'], $result['message']);
        Http::assertSent(function ($request) {
            $events = $request['events'] ?? [];
            $event = $events[0] ?? [];

            return $request->url() === Telemetry::endpoint()
                && $request->url() === 'https://monitoring.test/api/v1/events'
                && $request->hasHeader('License-Key', 'TEST-KEY')
                && ($event['type'] ?? null) === 'notification.preferences'
                && ($event['payload']['enabled'] ?? null) === true
                && ($event['payload']['notify_email'] ?? null) === 'ops@example.test'
                && ($event['payload']['server_probe_url'] ?? null) === 'https://engine.example.test';
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hub.example.test'));
        $this->assertNotSame('', Setting::get(NotificationPreferences::SETTING_PREFS_HASH));
    }

    /**
     * Emptying the monitoring host is "report nowhere". The old class-not-found
     * path never reached this branch; a missing class is not an empty URL.
     */
    public function test_sync_holds_when_monitoring_is_unset(): void
    {
        config(['monitoring.url' => '']);
        Http::fake();

        $result = NotificationPreferences::sync(true);

        $this->assertFalse($result['ok']);
        $this->assertSame('No monitoring endpoint configured', $result['message']);
        Http::assertNothingSent();
    }

    public function test_sync_skips_http_when_preferences_unchanged(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        $first = NotificationPreferences::sync(true);
        $this->assertTrue($first['ok'], $first['message']);
        $this->assertSame('Preferences synced to monitoring', $first['message']);
        Http::assertSentCount(1);

        $second = NotificationPreferences::sync(true);
        $this->assertTrue($second['ok'], $second['message']);
        $this->assertSame('Preferences unchanged, skipped', $second['message']);
        Http::assertSentCount(1);
    }

    public function test_sync_posts_again_when_email_changes(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        NotificationPreferences::sync(true);
        Http::assertSentCount(1);

        Setting::set(NotificationPreferences::SETTING_NOTIFY_EMAIL, 'new@example.test');
        $result = NotificationPreferences::sync(true);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Preferences synced to monitoring', $result['message']);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $event = $request['events'][0] ?? [];

            return ($event['payload']['notify_email'] ?? null) === 'new@example.test';
        });
    }

    public function test_force_sync_posts_even_when_hash_unchanged(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        NotificationPreferences::sync(true);
        Http::assertSentCount(1);

        $forced = NotificationPreferences::sync(true, force: true);
        $this->assertTrue($forced['ok'], $forced['message']);
        $this->assertSame('Preferences synced to monitoring', $forced['message']);
        Http::assertSentCount(2);
    }
}
