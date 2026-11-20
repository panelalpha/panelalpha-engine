<?php

namespace Tests\Integration\Console\Wizard;

use App\Auth\TokenAbilities;
use App\Console\Wizard\TokenManager;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ListedScope;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** The tokens of one kind: the list, minting, and revoking or deleting one. */
class TokenManagerTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const SCOPE = 0;

    private const REVOKE = 1;

    private const DELETE = 2;

    private const BACK = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        Carbon::setTestNow('2026-11-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_minting_shows_the_token_once_and_keeps_it_on_the_receipt(): void
    {
        $this->keys([Key::ENTER, 'deploy-bot'.Key::ENTER, '90d'.Key::ENTER, Key::ENTER, ...self::row(2)]);

        $manager = $this->manager();

        $this->assertSame(0, $manager->run());
        $token = PersonalAccessToken::query()->sole();
        $this->assertSame('deploy-bot', $token->name);
        $this->assertSame([TokenAbilities::MCP], $token->abilities);
        $this->assertSame('2027-02-18 12:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        $receipt = $manager->receipt();
        $this->assertSame(sprintf('Minted token %d, "deploy-bot" for an assistant — expires in 90d.', $token->id), $receipt[0]);
        $this->assertSame('It is shown once and cannot be retrieved again:', $receipt[1]);
        $this->assertMatchesRegularExpression('/^  '.$token->id.'\|\S{40,}$/', $receipt[2]);
        $this->assertScreenShows('There are no tokens for an assistant yet.');
        $this->assertScreenShows('Copy it now. This is the only time it is shown.');
        $this->assertScreenShows('Run the setup command.');
        $this->assertScreenShows('1 token(s) for an assistant, none limited.');
        $this->assertScreenShows('deploy-bot unlimited expires 2027-02-18');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function lifetimes(): array
    {
        return [
            'seconds' => ['30s', '2026-11-20 12:00:30'],
            'minutes' => ['30m', '2026-11-20 12:30:00'],
            'hours' => ['12h', '2026-11-21 00:00:00'],
            'weeks' => ['2w', '2026-12-04 12:00:00'],
            'days, with a space and a capital' => ['5 D', '2026-11-25 12:00:00'],
        ];
    }

    #[DataProvider('lifetimes')]
    public function test_a_lifetime_is_a_number_and_a_unit(string $lifetime, string $expires): void
    {
        $this->keys([Key::ENTER, 'bot'.Key::ENTER, $lifetime.Key::ENTER, Key::ENTER, ...self::row(2)]);

        $this->manager()->run();

        $this->assertSame($expires, PersonalAccessToken::query()->sole()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_lifetime_without_a_unit_is_refused_and_none_never_expires(): void
    {
        $this->keys([Key::ENTER, 'bot'.Key::ENTER, 'soon'.Key::ENTER, self::typed('', 'soon'), Key::ENTER, ...self::row(2)]);

        $manager = $this->manager();
        $manager->run();

        $this->assertScreenShows('Give a number and a unit, like 90d — or leave it empty.');
        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
        $this->assertStringEndsWith('— never expires.', $manager->receipt()[0]);
    }

    public function test_a_token_without_a_name_or_in_a_dry_run_is_not_minted(): void
    {
        $this->keys([Key::ENTER, Key::ENTER, ...self::row(1)]);
        $this->manager()->run();

        $this->keys([Key::ENTER, 'bot'.Key::ENTER, Key::ENTER, Key::ENTER, ...self::row(1)]);
        $dry = $this->manager(true);
        $dry->run();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame([], $dry->receipt());
        $this->assertScreenShows('Nothing was written: this was a dry run.');
    }

    public function test_a_mint_the_database_refuses_is_reported(): void
    {
        Admin::rootAccount();
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'tokens are read-only'); END");
        $this->keys([Key::ENTER, 'bot'.Key::ENTER, Key::ENTER, Key::ENTER, ...self::row(1)]);

        $manager = $this->manager();
        $manager->run();

        $this->assertScreenShows('tokens are read-only');
        $this->assertSame([], $manager->receipt());
    }

    public function test_revoking_keeps_the_row_and_a_second_revoke_says_it_already_was(): void
    {
        $token = $this->token('bot', ['mcp', 'mcp:alpha_list']);
        $this->keys([
            Key::ENTER, ...self::row(self::REVOKE), 'y', Key::ENTER, Key::ENTER,
            Key::ENTER, ...self::row(self::REVOKE), Key::ENTER, ...self::row(self::BACK),
            ...self::row(2),
        ]);

        $manager = $this->manager();
        $manager->run();

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame([sprintf('Revoked token %d ("bot"). It no longer works.', $token->id)], $manager->receipt());
        $this->assertScreenShows('1 token(s) for an assistant, 1 limited.');
        $this->assertScreenShows('May call: 1 of 3 commands');
        $this->assertScreenShows('bot 1 commands revoked');
        $this->assertScreenShows('Revoked: 2026-11-20 12:00:00');
        $this->assertScreenShows('Already revoked');
        $this->assertScreenShows(sprintf('Token %d was revoked on 2026-11-20 12:00:00 already.', $token->id));
    }

    public function test_declining_or_rehearsing_a_revoke_or_delete_changes_nothing(): void
    {
        $token = $this->token('bot', ['mcp']);
        $this->keys([
            Key::ENTER, ...self::row(self::REVOKE), Key::ENTER, ...self::row(self::DELETE), Key::ENTER,
            ...self::row(self::BACK), ...self::row(2),
        ]);
        $this->manager()->run();

        $this->keys([
            Key::ENTER, ...self::row(self::REVOKE), Key::ENTER, ...self::row(self::DELETE), Key::ENTER,
            ...self::row(self::BACK), ...self::row(2),
        ]);
        $dry = $this->manager(true);
        $dry->run();

        $this->assertNull($token->fresh()->revoked_at);
        $this->assertSame([], $dry->receipt());
        $this->assertScreenShows('Deleting removes the row.');
        $this->assertScreenShows('Nothing was written: this was a dry run.');
    }

    public function test_deleting_removes_the_row(): void
    {
        $token = $this->token('bot', ['mcp']);
        $this->keys([Key::ENTER, ...self::row(self::DELETE), 'y', Key::ENTER, Key::ENTER, ...self::row(1)]);

        $manager = $this->manager();
        $manager->run();

        $this->assertNull($token->fresh());
        $this->assertSame([sprintf('Deleted token %d ("bot").', $token->id)], $manager->receipt());
    }

    public function test_a_revoke_or_delete_the_database_refuses_is_reported(): void
    {
        $token = $this->token('bot', ['mcp']);
        DB::unprepared("CREATE TRIGGER refuse_update BEFORE UPDATE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'tokens are read-only'); END");
        DB::unprepared("CREATE TRIGGER refuse_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'tokens are kept'); END");
        $this->keys([
            Key::ENTER, ...self::row(self::REVOKE), 'y', Key::ENTER, Key::ENTER,
            ...self::row(self::DELETE), 'y', Key::ENTER, Key::ENTER,
            ...self::row(self::BACK), ...self::row(2),
        ]);

        $manager = $this->manager();
        $manager->run();

        $this->assertScreenShows('tokens are read-only');
        $this->assertScreenShows('tokens are kept');
        $this->assertNotNull($token->fresh());
        $this->assertSame([], $manager->receipt());
    }

    public function test_the_scope_editor_opens_from_a_token_and_its_receipt_is_kept(): void
    {
        $token = $this->token('bot', ['mcp']);
        $this->keys([
            Key::ENTER, ...self::row(self::SCOPE), ...self::row(1), Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER,
            ...self::row(self::BACK), ...self::row(2),
        ]);

        $manager = $this->manager();
        $manager->run();

        $this->assertSame([sprintf('Token %d ("bot") may now call 2 of the 3 commands this engine has.', $token->id)], $manager->receipt());
        $this->assertScreenShows('May call: 2 of 3 commands');
    }

    public function test_a_token_from_before_the_split_is_listed_and_flagged(): void
    {
        $this->token('legacy', ['*']);
        $this->token('rest-only', ['api']);
        $this->keys([Key::ENTER, ...self::row(self::BACK), ...self::row(2)]);

        $this->manager()->run();

        $this->assertScreenShows('1 token(s) for an assistant, none limited.');
        $this->assertScreenShows('May call: every command this engine has');
        $this->assertScreenShows('Expires: never');
        $this->assertScreenShows('Last used: never');
        $this->assertScreenShows('This token predates the split into kinds and is not limited to one.');
        $this->assertScreenDoesNotShow('rest-only');
    }

    public function test_the_tokens_of_the_other_kind_are_its_own(): void
    {
        $this->token('legacy', ['*']);
        $this->token('assistant', ['mcp']);
        $this->keys([...self::row(2)]);

        $manager = new TokenManager('API tokens', false, TokenAbilities::API, new ListedScope(), 'your own software', 'Narrow it…');

        $this->assertSame(0, $manager->run());
        $this->assertScreenShows('1 token(s) for your own software, none limited.');
        $this->assertScreenDoesNotShow('assistant');
    }

    public function test_tokens_that_cannot_be_read_end_the_section(): void
    {
        config(['database.default' => 'no-such-connection']);
        $this->keys([Key::ENTER]);

        $this->assertSame(1, $this->manager()->run());
        $this->assertScreenShows('Could not read the tokens: Database connection [no-such-connection] not configured.');
        $this->assertScreenShows('This needs the engine database.');
    }

    private function manager(bool $dryRun = false): TokenManager
    {
        return new TokenManager(
            title: 'MCP tokens',
            dryRun: $dryRun,
            ability: TokenAbilities::MCP,
            scope: new ListedScope(),
            noun: 'an assistant',
            narrow: 'Change which commands it may call…',
            mintHint: 'Run the setup command.',
        );
    }

    /** @param list<string> $abilities */
    private function token(string $name, array $abilities): PersonalAccessToken
    {
        return Admin::rootAccount()->createToken($name, $abilities)->accessToken;
    }
}
