<?php

namespace Tests\Unit\Backup;

use App\Lib\Backup\ArchivePriority;
use App\Models\Setting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two numbers an operator can give an archive, and the argv they become.
 * Nothing is set on a fresh install: an archive competes with the live sites
 * until someone asks for it not to.
 */
class ArchivePriorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->store(null, null);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_nothing_set_runs_an_archive_at_the_engines_own_priority(): void
    {
        $this->assertSame([], ArchivePriority::configured()->prefix());
    }

    public function test_a_settings_table_that_cannot_be_read_runs_an_archive_at_the_engines_own_priority(): void
    {
        Setting::clearRuntimeSettings();

        $this->assertSame([], ArchivePriority::configured()->prefix());
    }

    public function test_a_nice_value_alone_prefixes_nice(): void
    {
        $this->store('10', null);

        $this->assertSame(['nice', '-n', '10'], ArchivePriority::configured()->prefix());
    }

    public function test_an_io_class_alone_prefixes_ionice(): void
    {
        $this->store(null, '3');

        $this->assertSame(['ionice', '-c', '3'], ArchivePriority::configured()->prefix());
    }

    public function test_the_two_together_run_nice_then_ionice(): void
    {
        $this->store('10', '3');

        $this->assertSame(['nice', '-n', '10', 'ionice', '-c', '3'], ArchivePriority::configured()->prefix());
    }

    public function test_a_value_outside_its_range_is_ignored_rather_than_obeyed(): void
    {
        $this->store('99', '1');

        $this->assertSame([], ArchivePriority::configured()->prefix());
    }

    public function test_a_word_is_not_a_number(): void
    {
        $this->store('off', 'idle');

        $this->assertSame([], ArchivePriority::configured()->prefix());
    }

    public function test_a_written_pair_reads_back(): void
    {
        $stored = ArchivePriority::apply(15, ArchivePriority::IONICE_BEST_EFFORT);

        $this->assertSame(['nice' => 15, 'ionice_class' => 2], $stored->toArray());
        $this->assertSame(['nice' => 15, 'ionice_class' => 2], ArchivePriority::configured()->toArray());
        $this->assertSame('15', Setting::get(ArchivePriority::SETTING_NICE));
        $this->assertSame('2', Setting::get(ArchivePriority::SETTING_IONICE_CLASS));
    }

    public function test_writing_nothing_switches_both_off_again(): void
    {
        $this->store('10', '3');

        ArchivePriority::apply(null, null);

        $this->assertSame([], ArchivePriority::configured()->prefix());
        $this->assertNull(Setting::get(ArchivePriority::SETTING_NICE));
        $this->assertNull(Setting::get(ArchivePriority::SETTING_IONICE_CLASS));
    }

    public function test_a_value_out_of_range_cannot_be_written(): void
    {
        $this->expectException(RuntimeException::class);

        ArchivePriority::apply(20, null);
    }

    public function test_the_edges_are_what_a_value_may_be(): void
    {
        $this->assertNull(ArchivePriority::problem(ArchivePriority::NICE_MIN, ArchivePriority::IONICE_BEST_EFFORT));
        $this->assertNull(ArchivePriority::problem(ArchivePriority::NICE_MAX, ArchivePriority::IONICE_IDLE));
        $this->assertNull(ArchivePriority::problem(null, null));
        $this->assertNotNull(ArchivePriority::problem(ArchivePriority::NICE_MIN - 1, null));
        $this->assertNotNull(ArchivePriority::problem(ArchivePriority::NICE_MAX + 1, null));
        $this->assertNotNull(ArchivePriority::problem(null, 1));
        $this->assertNotNull(ArchivePriority::problem(null, ArchivePriority::IONICE_IDLE + 1));
    }

    private function store(?string $nice, ?string $ioniceClass): void
    {
        Setting::setRuntimeSettings([
            ArchivePriority::SETTING_NICE => $nice,
            ArchivePriority::SETTING_IONICE_CLASS => $ioniceClass,
        ]);
    }
}
