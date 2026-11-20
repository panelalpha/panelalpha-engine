<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\ConnectAssistant;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae connect` inside the wizard: an assistant, a token for it, and the command carrying both. */
class ConnectAssistantTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const GEMINI = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        Carbon::setTestNow('2026-11-20 12:00:00');
        config(['app.url' => 'https://engine.example.test:2011/']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_the_setup_command_carries_a_new_mcp_token_and_lands_on_the_receipt(): void
    {
        $this->keys([Key::ENTER, Key::ENTER, '30d'.Key::ENTER, Key::ENTER]);

        $connect = new ConnectAssistant('MCP tokens', false);
        $connect->run();

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame('claude', $token->name);
        $this->assertSame(['mcp'], $token->abilities);
        $this->assertSame('2026-12-20 12:00:00', $token->expires_at->format('Y-m-d H:i:s'));

        $receipt = $connect->receipt();
        $this->assertSame('To connect Claude Code, run this on your own computer:', $receipt[0]);
        $this->assertSame(sprintf('It carries token %d ("claude"), which expires in 30d. It is not shown again.', $token->id), end($receipt));
        $this->assertStringContainsString('https://engine.example.test:2011/mcp', implode("\n", $receipt));
        $this->assertStringContainsString($token->id.'|', implode("\n", $receipt));
        $this->assertScreenShows('What it does: installs the PanelAlpha Engine plugin');
        $this->assertScreenShows('Copy it now. The token inside it is shown only this once.');
        $this->assertScreenDoesNotShow('which no assistant can reach');
    }

    public function test_a_client_without_a_note_and_a_token_that_never_expires(): void
    {
        config(['app.url' => 'http://engine.example.test:2011']);
        $this->keys([...self::row(self::GEMINI), self::typed('gemini-laptop', 'gemini'), Key::ENTER, Key::ENTER]);

        $connect = new ConnectAssistant('MCP tokens', false);
        $connect->run();

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame('gemini-laptop', $token->name);
        $this->assertNull($token->expires_at);
        $receipt = $connect->receipt();
        $this->assertSame('To connect Gemini CLI, run this on your own computer:', $receipt[0]);
        $this->assertStringEndsWith('which never expires. It is not shown again.', end($receipt));
        $this->assertScreenShows("This engine's address is http://engine.example.test:2011. Most assistants refuse a plain http:// MCP server.");
        $this->assertScreenDoesNotShow('What it does:');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function lifetimes(): array
    {
        return [
            'seconds' => ['45s', '2026-11-20 12:00:45'],
            'minutes' => ['15m', '2026-11-20 12:15:00'],
            'hours' => ['6h', '2026-11-20 18:00:00'],
            'weeks' => ['1W', '2026-11-27 12:00:00'],
        ];
    }

    #[DataProvider('lifetimes')]
    public function test_a_lifetime_is_a_number_and_a_unit(string $lifetime, string $expires): void
    {
        $this->keys([Key::ENTER, Key::ENTER, $lifetime.Key::ENTER, Key::ENTER]);

        (new ConnectAssistant('MCP tokens', false))->run();

        $this->assertSame($expires, PersonalAccessToken::query()->sole()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_lifetime_without_a_unit_is_refused(): void
    {
        $this->keys([Key::ENTER, Key::ENTER, 'forever'.Key::ENTER, self::typed('1d', 'forever'), Key::ENTER]);

        (new ConnectAssistant('MCP tokens', false))->run();

        $this->assertScreenShows('Give a number and a unit, like 90d — or leave it empty.');
        $this->assertSame('2026-11-21 12:00:00', PersonalAccessToken::query()->sole()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_an_engine_without_a_reachable_address_is_warned_about_before_anything_is_minted(): void
    {
        config(['app.url' => '']);
        $this->keys([Key::CTRL_E, Key::ENTER]);

        $connect = new ConnectAssistant('MCP tokens', false);
        $connect->run();

        $this->assertScreenShows('This engine thinks its address is (unset), which no assistant can reach.');
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame([], $connect->receipt());
    }

    public function test_a_loopback_address_is_named_in_the_warning(): void
    {
        config(['app.url' => 'https://localhost:2011']);
        $this->keys([Key::CTRL_E, Key::ENTER]);

        (new ConnectAssistant('MCP tokens', false))->run();

        $this->assertScreenShows('This engine thinks its address is https://localhost:2011, which no assistant can reach.');
    }

    public function test_an_empty_name_or_a_dry_run_mints_nothing(): void
    {
        $this->keys([Key::ENTER, self::typed('', 'claude')]);
        (new ConnectAssistant('MCP tokens', false))->run();

        $this->keys([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);
        $dry = new ConnectAssistant('MCP tokens', true);
        $dry->run();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame([], $dry->receipt());
        $this->assertScreenShows('Nothing was written: this was a dry run. No token was minted.');
    }

    public function test_a_mint_the_database_refuses_is_reported(): void
    {
        Admin::rootAccount();
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'tokens are read-only'); END");
        $this->keys([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $connect = new ConnectAssistant('MCP tokens', false);
        $connect->run();

        $this->assertScreenShows('tokens are read-only');
        $this->assertSame([], $connect->receipt());
    }
}
