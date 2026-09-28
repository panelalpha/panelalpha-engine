<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Sections\TelemetrySection;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Models\Setting;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Whether reports are sent is the one thing in this section that lives in the
 * database; the rest is env. An engine whose database is unreachable used to
 * take the whole section down with it, where the sibling sections say which
 * half is unavailable and carry on.
 */
class TelemetrySectionTest extends TestCase
{
    private bool $fallback = false;

    protected function setUp(): void
    {
        parent::setUp();

        // An artisan command run earlier in the suite switches every prompt
        // to its non-interactive fallback, and nothing switches it back.
        $this->fallback = (new ReflectionProperty(Prompt::class, 'shouldFallback'))->getValue();
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, $this->fallback);

        parent::tearDown();
    }

    private function sending(TelemetrySection $section): ?bool
    {
        return (new ReflectionMethod($section, 'sending'))->invoke($section);
    }

    private function database(TelemetrySection $section): bool
    {
        return (new ReflectionProperty($section, 'database'))->getValue($section);
    }

    public function test_an_unreadable_setting_is_not_an_answer(): void
    {
        config(['database.default' => 'no-such-connection']);

        $section = new TelemetrySection();

        $this->assertNull($this->sending($section));
        $this->assertFalse($this->database($section), 'the failure should be remembered');
    }

    public function test_it_stops_asking_once_the_database_has_failed(): void
    {
        $section = new TelemetrySection();
        (new ReflectionProperty($section, 'database'))->setValue($section, false);

        // Would throw if it reached the settings table at all.
        config(['database.default' => 'no-such-connection']);

        $this->assertNull($this->sending($section));
    }

    /** Opening a toggle row and pressing Enter must leave it as it is (#255). */
    public function test_enter_on_sending_leaves_it_on(): void
    {
        Setting::setRuntimeSettings([NotificationPreferences::SETTING_TELEMETRY_ENABLED => '1']);
        Prompt::fake([Key::ENTER, Key::ENTER]);

        try {
            (new ReflectionMethod(TelemetrySection::class, 'setSending'))->invoke(new TelemetrySection(), true);
        } finally {
            Setting::clearRuntimeSettings();
        }

        Prompt::assertOutputContains('Left as it is');
        Prompt::assertOutputDoesntContain('Dry run');
    }

    public function test_enter_on_bug_reports_leaves_them_allowed(): void
    {
        config(['telemetry.bug_reports.enabled' => true]);
        Prompt::fake([Key::ENTER, Key::ENTER]);

        (new ReflectionMethod(TelemetrySection::class, 'setBugReports'))->invoke(new TelemetrySection(), true);

        Prompt::assertOutputContains('Left as it is');
        Prompt::assertOutputDoesntContain('Bug reports turned off');
    }

    public function test_answering_yes_still_flips_it(): void
    {
        config(['telemetry.bug_reports.enabled' => true]);
        Prompt::fake(['y', Key::ENTER, Key::ENTER]);

        (new ReflectionMethod(TelemetrySection::class, 'setBugReports'))->invoke(new TelemetrySection(), true);

        Prompt::assertOutputContains('Dry run: Bug reports turned off.');
    }
}
