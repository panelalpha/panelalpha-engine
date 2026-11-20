<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The values the preferences are read from: the stored telemetry switch, the email and the probe targets.
 */
class NotificationPreferenceValuesTest extends TestCase
{
    use InMemoryDatabase;

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function settings(array $settings): void
    {
        Setting::setRuntimeSettings($settings + [
            NotificationPreferences::SETTING_TELEMETRY_ENABLED => null,
            NotificationPreferences::SETTING_NOTIFY_EMAIL => null,
            'cert_domain' => null,
            'default_ipv4' => null,
        ]);
    }

    public function test_a_stored_switch_overrides_the_config_either_way(): void
    {
        config(['telemetry.enabled' => true]);
        foreach (['0', 'false'] as $stored) {
            $this->settings([NotificationPreferences::SETTING_TELEMETRY_ENABLED => $stored]);
            $this->assertFalse(NotificationPreferences::isTelemetryEnabled(), "stored '{$stored}'");
        }

        config(['telemetry.enabled' => false]);
        foreach (['1', 'true'] as $stored) {
            $this->settings([NotificationPreferences::SETTING_TELEMETRY_ENABLED => $stored]);
            $this->assertTrue(NotificationPreferences::isTelemetryEnabled(), "stored '{$stored}'");
        }
    }

    public function test_without_a_stored_switch_the_config_decides(): void
    {
        $this->settings([NotificationPreferences::SETTING_TELEMETRY_ENABLED => 'maybe']);

        config(['telemetry.enabled' => false]);
        $this->assertFalse(NotificationPreferences::isTelemetryEnabled());

        config(['telemetry.enabled' => true]);
        $this->assertTrue(NotificationPreferences::isTelemetryEnabled());
    }

    public function test_only_a_valid_email_is_a_notification_address(): void
    {
        $this->settings([NotificationPreferences::SETTING_NOTIFY_EMAIL => 'not-an-address']);
        $this->assertNull(NotificationPreferences::notifyEmail());

        $this->settings([NotificationPreferences::SETTING_NOTIFY_EMAIL => 'ops@example.test']);
        $this->assertSame('ops@example.test', NotificationPreferences::notifyEmail());
    }

    public function test_the_server_is_probed_on_its_certificate_name_first(): void
    {
        $this->settings(['cert_domain' => '.engine.example.test', 'default_ipv4' => TestNetwork::public(7)]);

        $this->assertSame('https://engine.example.test', NotificationPreferences::serverProbeUrl());
    }

    public function test_without_a_certificate_the_server_is_probed_on_its_direct_name(): void
    {
        $ip = TestNetwork::public(7);
        $this->settings(['default_ipv4' => $ip]);

        $this->assertSame('https://'.str_replace('.', '-', $ip).'.panelalpha.direct', NotificationPreferences::serverProbeUrl());
    }

    public function test_a_server_with_neither_name_nor_address_has_no_probe_target(): void
    {
        $this->settings(['default_ipv4' => 'not-an-ip']);

        $this->assertNull(NotificationPreferences::serverProbeUrl());
    }

    public function test_app_probe_targets_are_the_account_domains_once_each(): void
    {
        $this->bootInMemoryDatabase();
        $this->makeUser('one', [], 'one.example.test');
        $this->makeUser('two', [], 'http://two.example.test/');
        $this->makeUser('three', [], 'https://one.example.test/');
        $this->makeUser('four', [], '');

        $urls = NotificationPreferences::appProbeUrls();

        $this->assertSame(array_values($urls), $urls);
        $this->assertEqualsCanonicalizing(['https://one.example.test', 'http://two.example.test'], $urls);
    }

    public function test_app_probe_targets_are_empty_when_the_accounts_cannot_be_listed(): void
    {
        config(['database.default' => 'pa-no-such-connection']);

        $this->assertSame([], NotificationPreferences::appProbeUrls());
    }
}
