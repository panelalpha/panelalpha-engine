<?php

namespace Tests\Unit\Limits;

use App\Lib\Limits\LimitSelection;
use App\Lib\Limits\ResourceLimit;
use Tests\TestCase;

class LimitSelectionTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function opts(array $overrides = []): array
    {
        $options = [];
        foreach (ResourceLimit::all() as $limit) {
            $options[$limit->option] = null;
        }

        return array_merge($options, $overrides);
    }

    public function test_an_invocation_that_passes_nothing_selects_nothing(): void
    {
        $this->assertTrue(LimitSelection::fromOptions($this->opts())->isEmpty());
    }

    public function test_only_the_options_actually_passed_are_selected(): void
    {
        $selection = LimitSelection::fromOptions($this->opts([
            'memory-limit' => '512',
            'cpu-limit' => '1.5',
        ]));

        $this->assertSame(['memory_limit' => 512, 'cpu_limit' => 1.5], $selection->all());
    }

    /**
     * The distinction the command depends on: an absent option leaves the
     * stored value alone, while `--bandwidth-limit=-1` clears it.
     */
    public function test_minus_one_is_a_selection_not_an_absence(): void
    {
        $selection = LimitSelection::fromOptions($this->opts(['bandwidth-limit' => '-1']));

        $this->assertFalse($selection->isEmpty());
        $this->assertSame(['bandwidth_limit' => null], $selection->all());
    }

    /** Checked on the raw option, before normalize() folds -1 into null. */
    public function test_a_memory_limit_that_cannot_be_stored_is_rejected(): void
    {
        $this->assertSame(
            ['memory_limit' => 'The memory limit is in MB and must be a positive number.'],
            LimitSelection::rejections($this->opts(['memory-limit' => '-1']))
        );
        $this->assertSame([], LimitSelection::rejections($this->opts(['memory-limit' => '512'])));
        $this->assertSame([], LimitSelection::rejections($this->opts(['bandwidth-limit' => '-1'])));
        $this->assertSame([], LimitSelection::rejections($this->opts([])));
    }

    /** A limit already applied live does not also ask for a rebuild. */
    public function test_needs_rebuild_skips_what_was_applied_live(): void
    {
        $selection = LimitSelection::fromOptions($this->opts(['memory-limit' => '512']));

        $this->assertTrue($selection->needsRebuild());
        $this->assertFalse($selection->needsRebuild(['memory_limit']));

        $both = LimitSelection::fromOptions($this->opts(['memory-limit' => '512', 'inodes-limit' => '900']));
        $this->assertTrue($both->needsRebuild(['memory_limit']), 'inodes still needs one');
    }

    public function test_has_reports_only_what_was_selected(): void
    {
        $selection = LimitSelection::fromOptions($this->opts(['memory-limit' => '512']));

        $this->assertTrue($selection->has('memory_limit'));
        $this->assertFalse($selection->has('cpu_limit'));
    }

    public function test_disk_space_keeps_minus_one_where_others_clear(): void
    {
        $selection = LimitSelection::fromOptions($this->opts([
            'disk-space-limit' => '-1',
            'inodes-limit' => '-1',
        ]));

        $this->assertSame(['disk_space_limit' => -1, 'inodes_limit' => null], $selection->all());
    }

    public function test_zero_is_a_real_limit_and_is_not_mistaken_for_absent(): void
    {
        $selection = LimitSelection::fromOptions($this->opts(['ftp-accounts-limit' => '0']));

        $this->assertSame(['ftp_accounts_limit' => 0], $selection->all());
    }

    public function test_needs_rebuild_only_when_a_baked_in_limit_moved(): void
    {
        $this->assertTrue(LimitSelection::of(['inodes_limit' => 900])->needsRebuild());
        $this->assertTrue(LimitSelection::of(['cpu_limit' => 1.0])->needsRebuild());
        $this->assertFalse(LimitSelection::of(['bandwidth_limit' => 50])->needsRebuild());
        $this->assertFalse(LimitSelection::of(['subdomains_limit' => 3])->needsRebuild());
        $this->assertFalse(LimitSelection::of([])->needsRebuild());
    }

    public function test_of_rejects_a_key_that_is_not_a_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LimitSelection::of(['not_a_limit' => 1]);
    }

    public function test_the_option_hint_names_every_limit_once(): void
    {
        $hint = LimitSelection::optionListHint();

        foreach (ResourceLimit::all() as $limit) {
            $this->assertStringContainsString("`--{$limit->option}={$limit->argument}`", $hint);
        }
        $this->assertSame(count(ResourceLimit::all()) - 1, substr_count($hint, ' or '));
    }
}
