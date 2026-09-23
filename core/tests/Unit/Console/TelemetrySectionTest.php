<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Sections\TelemetrySection;
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
}
