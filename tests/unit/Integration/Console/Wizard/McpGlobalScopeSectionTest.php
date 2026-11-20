<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\McpGlobalScopeSection;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Support\EnvFile;
use Laravel\Prompts\Key;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure mcp-global`: ticks accumulate, and only Review and save writes the `MCP_*` lines. */
class McpGlobalScopeSectionTest extends TestCase
{
    use WizardScreen;

    private const GROUPS = 0;

    private const COMMANDS = 1;

    private const CEILING = 2;

    private const SAVE = 3;

    private const BACK = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWizardScreen();
        $this->serving([]);
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_turning_every_group_off_is_reviewed_then_written(): void
    {
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(false));
        $env = $this->envContents();
        $this->assertStringContainsString('MCP_TOOLSETS=none', $env);
        $this->assertStringContainsString('MCP_PERMISSION_MODE=full', $env);
        $this->assertSame('none', config('mcp-tools.toolsets'));
        $this->assertSame([], ToolExposure::current()->exposed());
        $receipt = $section->receipt();
        $this->assertSame('Updated '.$this->envPath().':', $receipt[0]);
        $this->assertContains(sprintf('  %-24s %s → %s', 'MCP_TOOLSETS', 'all', 'none'), $receipt);
        $this->assertSame('The file as it was is at '.$this->envPath().EnvFile::BACKUP_SUFFIX.'.', end($receipt));
        $this->assertFileExists($this->envPath().EnvFile::BACKUP_SUFFIX);
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

        $this->assertStringContainsString('MCP_PERMISSION_MODE=readonly', $this->envContents());
        $this->assertSame(ToolPolicy::MODE_READONLY, config('mcp-tools.permission_mode'));
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
        $this->assertStringNotContainsString('MCP_TOOLSETS', $this->envContents());
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
        $this->assertSame("APP_KEY=base64:dGVzdA==\n", $this->envContents());
    }

    public function test_a_dry_run_leaves_the_lines_on_the_receipt_only(): void
    {
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(true));
        $this->assertSame("APP_KEY=base64:dGVzdA==\n", $this->envContents());
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

    public function test_a_file_that_already_says_it_is_left_alone(): void
    {
        file_put_contents($this->envPath(), "APP_KEY=base64:dGVzdA==\nMCP_PERMISSION_MODE=full\nMCP_TOOLSETS=none\nMCP_TOOLS=\nMCP_DENIED_TOOLS=\nMCP_DENIED_TOOLS_REGEX=\n");
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(0, $section->run(false));
        $this->assertSame([$this->envPath().' already said that; nothing to do.'], $section->receipt());
        $this->assertSame('none', config('mcp-tools.toolsets'));
        $this->assertFileDoesNotExist($this->envPath().EnvFile::BACKUP_SUFFIX);
    }

    public function test_a_file_that_cannot_be_written_ends_the_section_with_an_error(): void
    {
        unlink($this->envPath());
        $this->keys([Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new McpGlobalScopeSection();

        $this->assertSame(1, $section->run(false));
        $this->assertScreenShows('No .env file at '.$this->envPath().'.');
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
