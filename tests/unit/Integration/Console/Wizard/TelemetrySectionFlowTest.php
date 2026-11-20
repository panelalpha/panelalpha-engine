<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\TelemetrySection;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure telemetry`: sending lives in the settings table, the rest in `.env`. */
class TelemetrySectionFlowTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const SENDING = 0;

    private const TIER = 1;

    private const BUNDLES = 2;

    private const BUGS = 3;

    private const SHOW = 4;

    private const BACK = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        Http::fake(['*' => Http::response(['ok' => true])]);
        config([
            'telemetry.enabled' => true,
            'telemetry.tier' => DeployReport::TIER_LOG,
            'telemetry.source_bundle.mode' => SourceBundlePolicy::MODE_OFF,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.spool_dir' => $this->wizardDir.'/outbox',
            'monitoring.url' => '',
        ]);
        Telemetry::resetCache();
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        Telemetry::resetCache();
        Setting::flushCache();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_stopping_reports_stores_the_setting_and_says_monitoring_was_not_told(): void
    {
        $this->keys([Key::ENTER, 'y', Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();

        $this->assertSame(0, $section->run(false));
        $this->assertSame('0', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertSame(['Telemetry sending is now OFF.'], $section->receipt());
        $this->assertScreenShows('Sending: on');
        $this->assertScreenShows('Queued: 0 report(s) waiting');
        $this->assertScreenShows('Sent to: (nowhere configured — reports are held)');
        $this->assertScreenShows('No monitoring endpoint configured — the local setting still stands.');
        $this->assertScreenShows('Stored as the `telemetry_enabled` setting, which overrides TELEMETRY_ENABLED in .env-core.');
        $this->assertScreenShows('Sending: off — reports are kept locally, not sent');
        Http::assertNothingSent();
    }

    public function test_starting_reports_tells_monitoring(): void
    {
        config(['monitoring.url' => 'https://monitoring.example.test']);
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '0');
        $this->keys([Key::ENTER, 'y', Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertSame('1', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertSame(['Telemetry sending is now ON.'], $section->receipt());
        $this->assertScreenShows('Reports are not being sent.');
        $this->assertScreenShows('Sent to: https://monitoring.example.test/');
        $this->assertScreenShows('Preferences synced to monitoring');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://monitoring.example.test/')
            && $request['events'][0]['payload']['enabled'] === true);
    }

    public function test_a_dry_run_of_sending_changes_nothing(): void
    {
        $this->keys([Key::ENTER, 'y', Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(true);

        $this->assertScreenShows('Dry run: nothing was changed.');
        $this->assertNull(Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertSame([], $section->receipt());
    }

    public function test_a_setting_that_silently_does_not_stick_is_reported(): void
    {
        DB::unprepared('CREATE TRIGGER ignore_insert BEFORE INSERT ON settings BEGIN SELECT RAISE(IGNORE); END');
        $this->keys([Key::ENTER, 'y', Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertScreenShows('The setting did not change.');
        $this->assertSame([], $section->receipt());
    }

    public function test_a_setting_the_database_refuses_is_reported(): void
    {
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
        $this->keys([Key::ENTER, 'y', Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertScreenShows('settings are read-only');
        $this->assertSame([], $section->receipt());
    }

    public function test_without_a_database_sending_is_unknown_and_cannot_be_changed(): void
    {
        config(['database.default' => 'no-such-connection']);
        $this->keys([Key::ENTER, Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertScreenShows('Sending: unknown — the database could not be read');
        $this->assertScreenShows('Sending unknown');
        $this->assertScreenShows('The database could not be read, so whether reports are sent cannot be shown or changed.');
        $this->assertScreenShows('The database could not be read, so this cannot be changed here.');
    }

    public function test_the_tier_is_written_to_the_env_file_and_read_back(): void
    {
        $this->keys([...self::row(self::TIER), ...self::row(0), Key::ENTER, ...self::row(self::TIER), ...self::row(1), Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertStringContainsString('TELEMETRY_TIER=1', $this->envContents());
        $this->assertSame(DeployReport::TIER_REPO, config('telemetry.tier'));
        $this->assertSame([
            'Reports now carry: what failed and when — nothing more',
            'Reports now carry: that, plus which repository',
        ], $section->receipt());
        $this->assertScreenShows('Reports carry: that, plus a redacted tail of the deploy log');
        $this->assertScreenShows('Reports carry: that, plus which repository');
    }

    public function test_a_dry_run_or_an_unwritable_file_leaves_the_tier_alone(): void
    {
        $this->keys([...self::row(self::TIER), ...self::row(0), Key::ENTER, ...self::row(self::BACK)]);
        $dry = new TelemetrySection();
        $dry->run(true);
        $this->assertScreenShows('Dry run: Reports now carry: what failed and when — nothing more');

        unlink($this->envPath());
        $this->keys([...self::row(self::TIER), ...self::row(0), Key::ENTER, ...self::row(self::BACK)]);
        $failed = new TelemetrySection();
        $failed->run(false);

        $this->assertScreenShows('No .env file at '.$this->envPath().'.');
        $this->assertScreenShows('There is no '.$this->envPath().' to write to.');
        $this->assertSame([], $dry->receipt());
        $this->assertSame([], $failed->receipt());
        $this->assertSame(DeployReport::TIER_LOG, config('telemetry.tier'));
    }

    public function test_uploading_source_has_to_be_confirmed(): void
    {
        $this->keys([
            ...self::row(self::BUNDLES), ...self::row(1), Key::ENTER, Key::ENTER,
            ...self::row(self::BUNDLES), ...self::row(2), 'y', Key::ENTER, Key::ENTER,
            ...self::row(self::BACK),
        ]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertStringContainsString('TELEMETRY_SOURCE_BUNDLE=failed', $this->envContents());
        $this->assertSame(SourceBundlePolicy::MODE_FAILED, config('telemetry.source_bundle.mode'));
        $this->assertSame(['Source code: uploaded whenever a deploy fails'], $section->receipt());
        $this->assertScreenShows('Left as it is — nothing was changed.');
        $this->assertScreenShows("Source bundles are on: a failed deploy uploads the application's own source code.");
    }

    public function test_turning_source_off_needs_no_confirmation(): void
    {
        config(['telemetry.source_bundle.mode' => SourceBundlePolicy::MODE_UNEXPLAINED]);
        $this->keys([...self::row(self::BUNDLES), ...self::row(0), Key::ENTER, ...self::row(self::BACK)]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertScreenShows('Source code: uploaded when a deploy fails unexplained');
        $this->assertStringContainsString('TELEMETRY_SOURCE_BUNDLE=off', $this->envContents());
        $this->assertSame(['Source code: never uploaded'], $section->receipt());
    }

    public function test_bug_reports_are_turned_off_and_back_on(): void
    {
        $this->keys([
            ...self::row(self::BUGS), 'y', Key::ENTER, Key::ENTER,
            ...self::row(self::BUGS), 'y', Key::ENTER, Key::ENTER,
            ...self::row(self::BACK),
        ]);

        $section = new TelemetrySection();
        $section->run(false);

        $this->assertStringContainsString('TELEMETRY_BUG_REPORTS=true', $this->envContents());
        $this->assertTrue(config('telemetry.bug_reports.enabled'));
        $this->assertSame(['Bug reports turned off.', 'Bug reports allowed.'], $section->receipt());
        $this->assertScreenShows('Bug reports off');
        $this->assertScreenShows('Allow bug reports?');
    }

    public function test_show_prints_what_telemetry_status_says(): void
    {
        Artisan::shouldReceive('call')->once()->with('telemetry:status')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("Queued: 3 reports\n");
        $this->keys([...self::row(self::SHOW), Key::ENTER, ...self::row(self::BACK)]);

        (new TelemetrySection())->run(false);

        $this->assertScreenShows('Queued: 3 reports');
    }

    public function test_show_prints_why_telemetry_status_could_not_run(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('telemetry:status is not available'));
        $this->keys([...self::row(self::SHOW), Key::ENTER, ...self::row(self::BACK)]);

        (new TelemetrySection())->run(false);

        $this->assertScreenShows('telemetry:status is not available');
    }

    public function test_an_env_file_without_an_app_key_is_named_as_probably_not_the_one_in_use(): void
    {
        file_put_contents($this->envPath(), "TELEMETRY_TIER=2\n");
        $this->keys([...self::row(self::BACK)]);

        (new TelemetrySection())->run(false);

        $this->assertScreenShows($this->envPath().' has no APP_KEY, so it is almost certainly not the file this engine boots from.');
    }
}
