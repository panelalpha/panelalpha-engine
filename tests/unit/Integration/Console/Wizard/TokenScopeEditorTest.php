<?php

namespace Tests\Integration\Console\Wizard;

use App\Auth\TokenAbilities;
use App\Console\Wizard\TokenScopeEditor;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ListedScope;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** One token's scope: ticks accumulate against what is saved, and only Review and save writes abilities. */
class TokenScopeEditorTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const GROUPS = 0;

    private const ITEMS = 1;

    private const SAVE = 2;

    private const BACK = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_unticking_one_command_of_an_unlimited_token_limits_it_to_the_rest(): void
    {
        $token = $this->token(['mcp']);
        $this->keys([...self::row(self::ITEMS), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $editor = $this->editor();
        $editor->edit($token);

        $this->assertSame(['alpha_create', 'beta_list'], $this->commands($token));
        $this->assertSame([sprintf('Token %d ("bot") may now call 2 of the 3 commands this engine has.', $token->id)], $editor->receipt());
        $this->assertScreenShows('alpha_list read');
        $this->assertScreenShows('alpha_create write');
        $this->assertScreenShows(sprintf('"bot" (token %d) would be able to call 2 of the 3 commands this engine has.', $token->id));
        $this->assertScreenShows('Stored on the token as 3 abilities.');
    }

    public function test_ticking_a_group_adds_all_of_its_commands(): void
    {
        $token = $this->token(['mcp', 'mcp:alpha_list']);
        $this->keys([...self::row(self::GROUPS), Key::DOWN, Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $this->editor()->edit($token);

        $this->assertSame(['alpha_list', 'beta_list'], $this->commands($token));
        $this->assertScreenShows('alpha 1 of 2');
        $this->assertScreenShows('Groups 1 of 2 groups on');
        $this->assertScreenShows('Groups 2 of 2 groups on');
    }

    public function test_unticking_a_group_removes_all_of_its_commands(): void
    {
        $token = $this->token(['mcp']);
        $this->keys([...self::row(self::GROUPS), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $this->editor()->edit($token);

        $this->assertSame(['beta_list'], $this->commands($token));
    }

    public function test_ticking_every_command_stores_no_limit_at_all(): void
    {
        $token = $this->token(['mcp', 'mcp:alpha_list']);
        $this->keys([...self::row(self::ITEMS), Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $editor = $this->editor();
        $editor->edit($token);

        $this->assertNull($this->commands($token));
        $this->assertSame(['mcp'], $token->fresh()->abilities);
        $this->assertSame([sprintf('Token %d ("bot") may now call every command this engine has.', $token->id)], $editor->receipt());
        $this->assertScreenShows('Every box is ticked, so it is stored as no limit at all.');
    }

    public function test_a_dry_run_says_what_it_would_give_and_writes_nothing(): void
    {
        $token = $this->token(['mcp']);
        $this->keys([...self::row(self::ITEMS), Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $editor = $this->editor(true);
        $editor->edit($token);

        $this->assertSame(['mcp'], $token->fresh()->abilities);
        $this->assertSame(['A dry run would have given "bot" 0 of 3 commands.'], $editor->receipt());
        $this->assertScreenShows('This token would be able to call nothing. Revoking it is the way to turn one off.');
        $this->assertScreenShows('Review it — this is a dry run, so nothing will be written');
    }

    public function test_saving_without_a_change_says_so_and_leaving_does_not_ask(): void
    {
        $token = $this->token(['mcp']);
        $this->keys([...self::row(self::SAVE), Key::ENTER, ...self::row(self::BACK)]);

        $editor = $this->editor();
        $editor->edit($token);

        $this->assertScreenShows('Nothing to save: that is what this token can already do.');
        $this->assertScreenShows('Review and save — nothing has changed yet');
        $this->assertSame([], $editor->receipt());
    }

    public function test_declining_the_save_keeps_the_ticks_until_leaving_is_confirmed(): void
    {
        $token = $this->token(['mcp']);
        $this->keys([
            ...self::row(self::ITEMS), Key::SPACE, Key::ENTER,
            Key::ENTER, 'n', Key::ENTER, Key::ENTER,
            ...self::row(self::BACK), Key::ENTER,
            ...self::row(self::BACK), 'y', Key::ENTER,
        ]);

        $editor = $this->editor();
        $editor->edit($token);

        $this->assertSame(['mcp'], $token->fresh()->abilities);
        $this->assertSame([], $editor->receipt());
        $this->assertScreenShows('Your ticks: 2 of 3 commands. (unsaved)');
        $this->assertScreenShows('Nothing was written. Your ticks are still here.');
        $this->assertScreenShows('Back, throwing these ticks away');
    }

    public function test_a_revoked_token_naming_a_command_that_is_gone_is_warned_about(): void
    {
        $token = $this->token(['mcp', 'mcp:alpha_list', 'mcp:gone_away']);
        $token->forceFill(['revoked_at' => Carbon::now()])->save();
        $this->keys([...self::row(self::BACK)]);

        $this->editor()->edit($token);

        $this->assertScreenShows(sprintf('Token %d, "bot", revoked.', $token->id));
        $this->assertScreenShows('May call now: 1 of 3 commands.');
        $this->assertScreenShows('It also names 1 this engine no longer has: gone_away. Saving drops them.');
    }

    public function test_a_save_the_database_refuses_is_reported_and_the_ticks_kept(): void
    {
        $token = $this->token(['mcp']);
        DB::unprepared("CREATE TRIGGER refuse_update BEFORE UPDATE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'tokens are read-only'); END");
        $this->keys([
            ...self::row(self::ITEMS), Key::SPACE, Key::ENTER,
            Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(self::BACK), 'y', Key::ENTER,
        ]);

        $editor = $this->editor();
        $editor->edit($token);

        $this->assertScreenShows('tokens are read-only');
        $this->assertSame(['mcp'], $token->fresh()->abilities);
        $this->assertSame([], $editor->receipt());
    }

    private function editor(bool $dryRun = false): TokenScopeEditor
    {
        return new TokenScopeEditor('MCP tokens', $dryRun, new ListedScope());
    }

    /** @param list<string> $abilities */
    private function token(array $abilities): PersonalAccessToken
    {
        return Admin::rootAccount()->createToken('bot', $abilities)->accessToken;
    }

    /** @return list<string>|null */
    private function commands(PersonalAccessToken $token): ?array
    {
        $commands = TokenAbilities::of($token->fresh())->commands();

        if ($commands !== null) {
            sort($commands);
        }

        return $commands;
    }
}
