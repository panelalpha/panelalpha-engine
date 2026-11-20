<?php

namespace Tests\Integration\Console\Wizard;

use App\Auth\ApiSurface;
use App\Auth\TokenAbilities;
use App\Console\Wizard\Scopes\CommandScope;
use App\Console\Wizard\Sections\ApiTokensSection;
use App\Console\Wizard\Sections\McpTokensSection;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure mcp-tokens` and `api-tokens`: each kind's tokens, scoped by its own surface. */
class TokenSectionsTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const CONNECT = 0;

    private const GLOBAL = 1;

    private const TOKENS = 2;

    private const BACK = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        config(['app.url' => 'https://engine.example.test:2011']);
        config(['mcp-tools' => array_merge(config('mcp-tools'), [
            'toolsets' => 'all',
            'tools' => '',
            'permission_mode' => ToolPolicy::MODE_FULL,
            'denied' => '',
            'denied_regex' => '',
        ])]);
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_connecting_then_listing_keeps_the_setup_command_on_the_receipt(): void
    {
        $this->keys([
            Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(self::TOKENS), ...self::row(2),
            ...self::row(self::BACK),
        ]);

        $section = new McpTokensSection();

        $this->assertSame(0, $section->run(false));
        $this->assertSame('To connect Claude Code, run this on your own computer:', $section->receipt()[0]);
        $total = count(ToolRegistry::all());
        $this->assertScreenShows("Global scope: {$total} of {$total} commands, ceiling full.");
        $this->assertScreenShows('Tokens: none yet.');
        $this->assertScreenShows('Tokens: 1, none limited.');
        $this->assertScreenShows('1 token(s) for an assistant, none limited.');
    }

    public function test_limiting_a_token_through_the_section_uses_what_the_engine_offers(): void
    {
        $token = $this->token(['mcp']);
        $offered = ToolExposure::current()->exposedNames();
        $this->keys([
            ...self::row(self::TOKENS), Key::ENTER,
            ...self::row(0), ...self::row(1), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(3), ...self::row(2),
            ...self::row(self::BACK),
        ]);

        $section = new McpTokensSection();
        $section->run(false);

        $commands = TokenAbilities::of($token->fresh())->commands();
        $this->assertCount(count($offered) - 1, $commands);
        $this->assertSame([sprintf('Token %d ("bot") may now call %d of the %d commands this engine has.', $token->id, count($offered) - 1, count($offered))], $section->receipt());
        $this->assertScreenShows(sprintf('Tokens: 1, 1 limited (smallest: %d of %d commands).', count($offered) - 1, count($offered)));
    }

    public function test_the_global_scope_is_reachable_and_its_failure_ends_the_section(): void
    {
        unlink($this->envPath());
        $this->keys([...self::row(self::GLOBAL), Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER]);

        $this->assertSame(1, (new McpTokensSection())->run(false));
        $this->assertScreenShows('No .env file at '.$this->envPath().'.');
    }

    public function test_a_dry_run_of_the_global_scope_comes_back_with_its_receipt(): void
    {
        $this->keys([
            ...self::row(self::GLOBAL), Key::ENTER, Key::CTRL_A, Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(self::BACK),
        ]);

        $section = new McpTokensSection();

        $this->assertSame(0, $section->run(true));
        $this->assertSame('A dry run of mcp-global would have written:', $section->receipt()[0]);
    }

    public function test_an_unreadable_database_is_not_reported_in_the_header(): void
    {
        config(['database.default' => 'no-such-connection']);
        $this->keys([...self::row(self::BACK)]);

        $this->assertSame(0, (new McpTokensSection())->run(false));
        $this->assertScreenShows('Tokens: unknown — the database could not be read.');
    }

    public function test_api_tokens_are_minted_for_the_api_and_limited_by_route(): void
    {
        $routes = array_merge(...array_values(ApiSurface::byGroup()));
        $this->keys([
            Key::ENTER, 'deploy-script'.Key::ENTER, Key::ENTER, Key::ENTER,
            Key::ENTER, ...self::row(0), ...self::row(1), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(3), ...self::row(2),
        ]);

        $section = new ApiTokensSection();

        $this->assertSame(0, $section->run(false));
        $token = PersonalAccessToken::query()->sole();
        $this->assertContains(TokenAbilities::API, $token->abilities);
        $this->assertCount(count($routes) - 1, TokenAbilities::of($token)->routes());
        $receipt = $section->receipt();
        $this->assertStringContainsString('"deploy-script" for your own software — never expires.', $receipt[0]);
        $this->assertSame(sprintf('Token %d ("deploy-script") may now call %d of the %d operations this engine has.', $token->id, count($routes) - 1, count($routes)), end($receipt));
        $this->assertScreenShows('Send it as `Authorization: Bearer <token>`.');
        $this->assertScreenShows('Change which parts of the API it may call…');
    }

    public function test_a_command_scope_notes_what_a_command_does_and_nothing_for_an_unknown_one(): void
    {
        $scope = new CommandScope();
        $policy = new ToolPolicy();
        $first = ToolRegistry::all()[0];

        $this->assertSame($policy->accessOf($first), $scope->note($policy->nameOf($first)));
        $this->assertSame('', $scope->note('no_such_command'));
    }

    /** @param list<string> $abilities */
    private function token(array $abilities): PersonalAccessToken
    {
        return Admin::rootAccount()->createToken('bot', $abilities)->accessToken;
    }
}
