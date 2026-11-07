<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What sync() sends and answers on the paths the endpoint test leaves out:
 * the exact request, a refused post, a post that never arrives.
 */
class NotificationPreferencesSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Setting::setRuntimeSettings([
            'email' => 'ops@example.test',
            'cert_domain' => '.engine.example.test',
            'license_key' => '',
            NotificationPreferences::SETTING_TELEMETRY_ENABLED => '1',
            NotificationPreferences::SETTING_PREFS_HASH => '',
        ]);
        config([
            'monitoring.url' => 'https://monitoring.test',
            'telemetry.reports_path' => Telemetry::EVENTS_PATH,
            'telemetry.timeout' => 5,
            'telemetry.token' => '  ingest-token  ',
            'app.uid' => 'install-42',
        ]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_the_request_carries_the_preferences_and_the_credentials_configured(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 200)]);

        $result = NotificationPreferences::sync(false);

        $this->assertSame(['ok' => true, 'message' => 'Preferences synced to monitoring'], $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $events = $request->data()['events'];
            $event = $events[0];

            $this->assertSame(['events'], array_keys($request->data()));
            $this->assertCount(1, $events);
            $this->assertSame(['type', 'occurred_at', 'panel_url', 'payload'], array_keys($event));
            $this->assertSame('notification.preferences', $event['type']);
            $this->assertSame('https://engine.example.test', $event['panel_url']);
            $this->assertSame([
                'enabled' => false,
                'notify_email' => 'ops@example.test',
                'server_probe_url' => 'https://engine.example.test',
                'app_probe_urls' => [],
            ], $event['payload']);
            $this->assertSame('https://monitoring.test/api/v1/events', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer ingest-token'));
            $this->assertTrue($request->hasHeader('User-Agent', 'PanelAlpha-Engine/notification-preferences'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertFalse($request->hasHeader('License-Key'));
            $this->assertTrue($request->hasHeader('X-Engine-App-UID', 'install-42'));

            return true;
        });
        $this->assertSame(
            NotificationPreferences::payloadFingerprint([
                'enabled' => false,
                'notify_email' => 'ops@example.test',
                'server_probe_url' => 'https://engine.example.test',
                'app_probe_urls' => [],
            ]),
            Setting::get(NotificationPreferences::SETTING_PREFS_HASH)
        );
    }

    public function test_no_token_sends_no_authorization_header(): void
    {
        config(['telemetry.token' => '   ']);
        Http::fake(['*' => Http::response([], 200)]);

        NotificationPreferences::sync(false);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_a_refused_post_reports_the_status_and_body_and_remembers_nothing(): void
    {
        Http::fake(['*' => Http::response('maintenance', 503)]);

        $result = NotificationPreferences::sync(false);

        $this->assertSame(['ok' => false, 'message' => 'HTTP 503: maintenance'], $result);
        $this->assertSame('', Setting::get(NotificationPreferences::SETTING_PREFS_HASH));
    }

    public function test_a_post_that_never_arrives_reports_why(): void
    {
        Http::fake(function (): void {
            throw new \RuntimeException('connection refused by peer');
        });

        $result = NotificationPreferences::sync(false);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('connection refused by peer', $result['message']);
        $this->assertSame('', Setting::get(NotificationPreferences::SETTING_PREFS_HASH));
    }

    public function test_an_unchanged_fingerprint_skips_the_post_unless_forced(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        NotificationPreferences::sync(false);

        $skipped = NotificationPreferences::sync(false);
        $forced = NotificationPreferences::sync(false, true);

        $this->assertSame(['ok' => true, 'message' => 'Preferences unchanged, skipped'], $skipped);
        $this->assertSame(['ok' => true, 'message' => 'Preferences synced to monitoring'], $forced);
        Http::assertSentCount(2);
    }

    public function test_no_endpoint_is_answered_before_anything_is_read(): void
    {
        config(['monitoring.url' => '']);
        Http::fake();

        $this->assertSame(
            ['ok' => false, 'message' => 'No monitoring endpoint configured'],
            NotificationPreferences::sync(false)
        );
        Http::assertNothingSent();
    }
}
