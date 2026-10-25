<?php

namespace Tests\Integration\Console;

use App\Console\Wizard\Sections\BackupPrioritySection;
use App\Lib\Backup\ArchivePriority;
use App\Models\Setting;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Tests\TestCase;

/**
 * `pae configure backups` stores the same setting the API does, and a dry run
 * stores nothing.
 */
class BackupPrioritySectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Setting::setRuntimeSettings([
            ArchivePriority::SETTING_NICE => null,
            ArchivePriority::SETTING_IONICE_CLASS => null,
        ]);
        (new \ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Prompt::interactive(false);
        Setting::clearRuntimeSettings();

        parent::tearDown();
    }

    public function test_the_two_answers_are_stored(): void
    {
        Prompt::fake(['1', '0', Key::ENTER, '3', Key::ENTER, Key::ENTER]);

        $section = new BackupPrioritySection();
        $this->assertSame(0, $section->run(false));

        $this->assertSame(['nice' => 10, 'ionice_class' => 3], ArchivePriority::configured()->toArray());
        $this->assertNotSame([], $section->receipt());
    }

    public function test_a_dry_run_stores_nothing(): void
    {
        Prompt::fake(['1', '0', Key::ENTER, '3', Key::ENTER, Key::ENTER]);

        $section = new BackupPrioritySection();
        $this->assertSame(0, $section->run(true));

        $this->assertSame(['nice' => null, 'ionice_class' => null], ArchivePriority::configured()->toArray());
        $this->assertSame([], $section->receipt());
    }
}
