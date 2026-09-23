<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use Tests\TestCase;

/**
 * `ToolExposure` is what `pae configure` shows the operator before it writes
 * anything, so the numbers beside each choice have to be the numbers the
 * server would then serve. It answers with `ToolPolicy`, against the real tool
 * registry, for exactly that reason.
 */
class ToolExposureTest extends TestCase
{
    /** The shipped default: every group, full ceiling, nothing withheld. */
    public function test_the_default_exposes_everything(): void
    {
        $exposure = new ToolExposure(['toolsets' => 'all', 'permission_mode' => 'full']);

        $this->assertCount(count(ToolRegistry::all()), $exposure->exposed());
        $this->assertSame([], $exposure->withheld());
    }

    public function test_it_agrees_with_the_policy_it_would_configure(): void
    {
        $config = ['toolsets' => 'projects,domains', 'permission_mode' => 'modify'];

        $this->assertSame(
            (new ToolPolicy($config))->filter(ToolRegistry::all()),
            (new ToolExposure($config))->exposed()
        );
    }

    public function test_narrowing_the_groups_narrows_the_answer(): void
    {
        $all = new ToolExposure(['toolsets' => 'all', 'permission_mode' => 'full']);
        $narrow = $all->with(['toolsets' => 'projects']);

        $this->assertLessThan(count($all->exposed()), count($narrow->exposed()));
        $this->assertSame(['projects'], $narrow->enabledToolsets());

        // with() answers a question; it does not change the thing asked.
        $this->assertCount(count(ToolRegistry::all()), $all->exposed());
    }

    /**
     * A blank ceiling is not a missing one: `ToolPolicy` reads an unusable
     * value as `readonly` on purpose, and the wizard has to report the engine
     * the operator actually has rather than the one config/mcp-tools.php would
     * have defaulted to.
     */
    public function test_a_blank_ceiling_reads_as_readonly(): void
    {
        $exposure = new ToolExposure(['toolsets' => 'all']);

        $this->assertSame(ToolPolicy::MODE_READONLY, $exposure->policy()->mode());
        $this->assertNotContains('project_delete', $exposure->exposedNames());
    }

    /**
     * `all` and an empty value mean the same thing to `ToolPolicy`, and the
     * wizard has to tick every box for both rather than showing an empty list.
     */
    public function test_all_and_empty_both_expand_to_every_group(): void
    {
        $every = array_keys(ToolRegistry::byToolset(new ToolPolicy()));

        $this->assertSame($every, (new ToolExposure(['toolsets' => 'all']))->enabledToolsets());
        $this->assertSame($every, (new ToolExposure(['toolsets' => '']))->enabledToolsets());
    }

    /** A group named that does not exist is dropped, not shown as a ticked box. */
    public function test_a_group_that_does_not_exist_is_not_enabled(): void
    {
        $exposure = new ToolExposure(['toolsets' => 'projects,made_up']);

        $this->assertSame(['projects'], $exposure->enabledToolsets());
    }

    public function test_the_ceiling_is_a_ceiling(): void
    {
        $exposure = new ToolExposure(['toolsets' => 'all', 'permission_mode' => 'readonly']);

        $this->assertLessThan(count(ToolRegistry::all()), count($exposure->exposed()));

        // Naming a destructive tool does not smuggle it past the ceiling --
        // the wizard's mode counts would be a lie if it did.
        $named = $exposure->with(['tools' => 'project_delete']);

        $this->assertNotContains('project_delete', $named->exposedNames());
    }

    public function test_the_group_counts_add_up_to_the_whole(): void
    {
        $exposure = new ToolExposure(['toolsets' => 'projects,domains']);
        $counts = $exposure->toolsets();

        $this->assertSame(
            count($exposure->exposed()),
            array_sum(array_column($counts, 'exposed'))
        );
        $this->assertSame(
            count(ToolRegistry::all()),
            array_sum(array_column($counts, 'total')),
            'every tool belongs to exactly one group'
        );
        $this->assertSame(0, $counts['csf']['exposed'], 'a group left off is off');
    }

    /**
     * Every key is written, including the empty ones: the file is read by
     * people, and a key that is present and empty says the question was
     * answered.
     */
    public function test_every_setting_is_written_down(): void
    {
        $lines = (new ToolExposure(['toolsets' => 'projects']))->envLines();

        $this->assertSame(array_values(ToolExposure::ENV_KEYS), array_keys($lines));
        $this->assertSame('projects', $lines['MCP_TOOLSETS']);
        $this->assertSame('', $lines['MCP_DENIED_TOOLS']);
    }

    /** Anything not a setting of this config is not carried into the file. */
    public function test_it_ignores_keys_that_are_not_settings(): void
    {
        $lines = (new ToolExposure(['toolsets' => 'projects', 'nonsense' => 'x']))->envLines();

        $this->assertNotContains('x', $lines);
        $this->assertCount(count(ToolExposure::ENV_KEYS), $lines);
    }

    /**
     * What the wizard's menu means by "changed", and what it offers to save.
     * The comparison is of the lines that would be written, so a difference
     * `.env` cannot express is not one.
     */
    public function test_diff_names_the_settings_that_would_be_written_differently(): void
    {
        $current = new ToolExposure(['toolsets' => 'all', 'permission_mode' => 'full']);

        $this->assertSame([], $current->diff($current));

        $this->assertSame(
            ['MCP_PERMISSION_MODE'],
            $current->with(['permission_mode' => 'readonly'])->diff($current)
        );

        $this->assertSame(
            ['MCP_PERMISSION_MODE', 'MCP_TOOLSETS'],
            $current->with(['permission_mode' => 'modify', 'toolsets' => 'projects'])->diff($current)
        );
    }

    /** Answering with what is already there is not a change to save. */
    public function test_answering_the_same_way_is_not_a_change(): void
    {
        $current = new ToolExposure(['toolsets' => 'projects', 'permission_mode' => 'modify']);

        $this->assertSame([], $current->with(['toolsets' => 'projects'])->diff($current));
    }

    /**
     * The trap the sentinel exists for. `ToolPolicy` reads an empty
     * `MCP_TOOLSETS` as *every* group, so "the operator ticked nothing" and
     * "the operator said nothing" cannot share a spelling — writing the empty
     * one would turn every command on at the moment they were all turned off.
     */
    public function test_ticking_nothing_exposes_nothing(): void
    {
        $none = $this->everything()->selecting([]);

        $this->assertSame(ToolExposure::NO_TOOLSETS, $none->get('toolsets'));
        $this->assertSame([], $none->exposed());
    }

    /** ...and the sentinel has to stay a name no real group answers to. */
    public function test_the_sentinel_is_not_a_real_group(): void
    {
        $this->assertNotContains(
            ToolExposure::NO_TOOLSETS,
            array_keys(ToolRegistry::byToolset(new ToolPolicy()))
        );
    }

    public function test_ticking_everything_is_written_as_all(): void
    {
        $every = $this->everything();
        $same = $every->selecting($every->selectedNames());

        $this->assertSame(ToolExposure::ALL_TOOLSETS, $same->get('toolsets'));
        $this->assertSame('', $same->get('tools'));
        $this->assertSame('', $same->get('denied'));
    }

    /** Whatever the ticks are, writing them down and reading them back agrees. */
    public function test_a_tick_state_survives_being_written_down(): void
    {
        $every = $this->everything();

        foreach ([
            'one group' => $this->namesIn('projects'),
            'one command' => ['project_list'],
            'a group less one' => array_values(array_diff($this->namesIn('projects'), ['project_delete'])),
            'two groups and a stray' => array_merge($this->namesIn('domains'), $this->namesIn('files'), ['metrics_latest']),
        ] as $case => $ticks) {
            sort($ticks);

            $this->assertSame($ticks, $every->selecting($ticks)->selectedNames(), $case);
        }
    }

    /**
     * Which of the three settings carries a partial group is a question of
     * what reads better in the file, not of meaning: one ticked command out of
     * fifteen is one line, and fourteen exceptions is fourteen.
     */
    public function test_a_partial_group_is_written_the_shorter_way(): void
    {
        $every = $this->everything();
        $projects = $this->namesIn('projects');

        $one = $every->selecting(['project_list']);
        $this->assertSame('project_list', $one->get('tools'));
        $this->assertSame(ToolExposure::NO_TOOLSETS, $one->get('toolsets'));

        $allButOne = $every->selecting(array_values(array_diff($projects, ['project_delete'])));
        $this->assertSame('projects', $allButOne->get('toolsets'));
        $this->assertSame('project_delete', $allButOne->get('denied'));
    }

    /**
     * A command the ceiling holds back is still ticked. Reading it as unticked
     * would write the ceiling into the denylist, and raising the ceiling again
     * would then bring nothing back.
     */
    public function test_the_ceiling_is_not_a_tick(): void
    {
        $readonly = $this->everything()->with(['permission_mode' => 'readonly']);

        $this->assertContains('project_delete', $readonly->selectedNames());
        $this->assertNotContains('project_delete', $readonly->exposedNames());

        $rewritten = $readonly->selecting($readonly->selectedNames());

        $this->assertSame('', $rewritten->get('denied'));
        $this->assertSame('readonly', $rewritten->get('permission_mode'), 'the ceiling is carried through');
        $this->assertContains(
            'project_delete',
            $rewritten->with(['permission_mode' => 'full'])->exposedNames(),
            'raising the ceiling again brings it back'
        );
    }

    public function test_a_denial_wins_over_what_enabled_the_tool(): void
    {
        $exposure = new ToolExposure([
            'toolsets' => 'all',
            'permission_mode' => 'full',
            'tools' => 'project_delete',
            'denied' => '*_delete',
        ]);

        $this->assertNotContains('project_delete', $exposure->exposedNames());
        $this->assertSame(['*_delete'], $exposure->deniedPatterns());
    }

    private function everything(): ToolExposure
    {
        return new ToolExposure([
            'toolsets' => ToolExposure::ALL_TOOLSETS,
            'permission_mode' => 'full',
        ]);
    }

    /** @return array<int, string> */
    private function namesIn(string $toolset): array
    {
        $policy = new ToolPolicy();

        return array_map(
            fn (string $class): string => $policy->nameOf($class),
            ToolRegistry::byToolset($policy)[$toolset]
        );
    }
}
