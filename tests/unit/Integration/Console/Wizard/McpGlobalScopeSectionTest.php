<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\McpGlobalScopeSection;
use App\Mcp\McpScopeSettings;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure mcp-global`: ticks accumulate, and only Review and save stores the `MCP_*` settings. */
class McpGlobalScopeSectionTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const GROUPS = 0;

    private const COMMANDS = 1;

    private const CEILING = 2;

    private const SAVE = 3;

    private const BACK = 4;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        $this->serving([]);
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_turning_every_group_off_is_reviewed_then_written(): void
    {
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(false));
        $this->assertSame('none', $this->stored('toolsets'));
        $this->assertSame('full', $this->stored('permission_mode'));
        $this->assertSame("APP_KEY=base64:dGVzdA==\n", $this->envContents());
        $this->assertSame([], ToolExposure::current()->exposed());
        $receipt = $section->receipt();
        $this->assertSame('Saved in the engine database, where it wins over .env-core:', $receipt[0]);
        $this->assertContains(sprintf('  %-24s %s → %s', 'MCP_TOOLSETS', 'all', 'none'), $receipt);
        $this->assertSame('GET /api/system/mcp-scope reads it; PUT with null for a field puts .env-core back in charge.', end($receipt));
        $this->assertScreenShows('The assistant would see no commands at all, and could do nothing with this engine.');
        $this->assertScreenShows(sprintf('0 of %d commands would be offered, against %d now.', count(ToolRegistry::all()), count(ToolRegistry::all())));
        $this->assertScreenShows('Your ticks: 0 of '.count(ToolRegistry::all()).' commands, ceiling full. (unsaved)');
        $this->assertScreenShows('Reconnect it. If it still sees the old list, restart the engine.');
    }

    public function test_unticking_one_command_writes_it_to_the_denylist(): void
    {
        $policy = ToolExposure::current()->policy();
        $first = $policy->nameOf(array_values(ToolRegistry::byToolset($policy))[0][0]);
        $groups = count(ToolExposure::current()->toolsets());
        $this->keys([...self::row(self::COMMANDS), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();
        $section->run(false);

        $this->assertNotContains($first, ToolExposure::current()->exposedNames());
        $this->assertCount(count(ToolRegistry::all()) - 1, ToolExposure::current()->exposed());
        $this->assertScreenShows(sprintf('%d group(s) are unaffected.', $groups - 1));
    }

    public function test_a_lower_ceiling_is_written_and_shown_against_each_command(): void
    {
        $readonly = count(ToolExposure::current()->with(['permission_mode' => ToolPolicy::MODE_READONLY])->exposed());
        $this->keys([
            ...self::row(self::CEILING), ...self::row(0),
            ...self::row(self::COMMANDS), Key::ENTER,
            ...self::row(self::GROUPS), Key::ENTER,
            ...self::row(self::SAVE), Key::ENTER, Key::ENTER,
        ]);

        $section = new McpGlobalScopeSection();
        $section->run(false);

        $this->assertSame(ToolPolicy::MODE_READONLY, $this->stored('permission_mode'));
        $this->assertSame(ToolPolicy::MODE_READONLY, ToolExposure::current()->get('permission_mode'));
        $this->assertScreenShows('readonly — they may look, and change nothing');
        $this->assertScreenShows(sprintf('(%d of the ticked commands)', $readonly));
        $this->assertScreenShows('(above the ceiling)');
        $this->assertMatchesRegularExpression('/\(\d+ above the ceiling\)/', $this->screen());
    }

    public function test_the_modify_ceiling_is_described_as_such(): void
    {
        $this->serving(['permission_mode' => ToolPolicy::MODE_MODIFY]);
        $this->keys([...self::row(self::BACK)]);

        (new McpGlobalScopeSection())->run(false);

        $this->assertScreenShows('modify — they may create and update, but no DELETE routes');
        $this->assertScreenShows('Serving now: '.count(ToolExposure::current()->exposed()).' of '.count(ToolRegistry::all()).' commands, ceiling modify.');
    }

    public function test_declining_the_write_keeps_the_ticks_and_leaving_asks_first(): void
    {
        $this->keys([
            Key::ENTER, Key::CTRL_A, Key::ENTER,
            Key::ENTER, 'n', Key::ENTER, Key::ENTER,
            ...self::row(self::BACK), Key::ENTER,
            ...self::row(self::BACK), 'y', Key::ENTER,
        ]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(false));
        $this->assertNull($this->stored('toolsets'));
        $this->assertSame([], $section->receipt());
        $this->assertScreenShows('Nothing was written. Your ticks are still here.');
        $this->assertScreenShows('Back, throwing these ticks away');
        $this->assertScreenShows('Leave without saving? The ticks you made will be thrown away.');
    }

    public function test_saving_without_a_change_says_there_is_nothing_to_save(): void
    {
        $this->keys([...self::row(self::SAVE), Key::ENTER, ...self::row(self::BACK)]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Nothing to save: this is what the engine is already serving.');
        $this->assertNull($this->stored('toolsets'));
    }

    public function test_a_dry_run_leaves_the_lines_on_the_receipt_only(): void
    {
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(true));
        $this->assertNull($this->stored('toolsets'));
        $this->assertSame([
            'A dry run of mcp-global would have written:',
            'MCP_PERMISSION_MODE=full',
            'MCP_TOOLSETS=none',
            'MCP_TOOLS=',
            'MCP_DENIED_TOOLS=',
            'MCP_DENIED_TOOLS_REGEX=',
        ], $section->receipt());
        $this->assertScreenShows('Review it — this is a dry run, so nothing will be written');
    }

    public function test_a_database_that_refuses_the_write_ends_the_section_with_an_error(): void
    {
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
        DB::unprepared("CREATE TRIGGER refuse_update BEFORE UPDATE ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(1, $section->run(false));
        $this->assertScreenShows('settings are read-only');
        $this->assertSame([], $section->receipt());
    }

    public function test_the_regex_denylist_is_named_and_kept(): void
    {
        $this->serving(['denied_regex' => '/delete/']);
        $this->keys([...self::row(self::BACK)]);

        (new McpGlobalScopeSection())->run(false);

        $this->assertScreenShows('MCP_DENIED_TOOLS_REGEX=/delete/ is also in force, and this wizard leaves it alone.');
    }

    /** Reached from the MCP tokens menu rather than the top one, and worded for that area alone. */
    public function test_it_is_named_for_assistants_and_never_the_rest_api(): void
    {
        $this->assertSame('mcp-global', McpGlobalScopeSection::key());
        $this->assertStringNotContainsString('rest', strtolower(McpGlobalScopeSection::label().' '.McpGlobalScopeSection::hint()));
        $this->assertStringContainsString('assistants', McpGlobalScopeSection::hint());
    }

    private function stored(string $key): ?string
    {
        return McpScopeSettings::setting($key)->stored();
    }

    /** @param array<string, string> $overrides */
    private function serving(array $overrides): void
    {
        config(['mcp-tools' => array_merge(config('mcp-tools'), [
            'toolsets' => 'all',
            'tools' => '',
            'permission_mode' => ToolPolicy::MODE_FULL,
            'denied' => '',
            'denied_regex' => '',
        ], $overrides)]);
    }
}
