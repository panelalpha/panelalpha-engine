<?php

namespace App\Console\Wizard\Sections;

use App\Auth\TokenAbilities;
use App\Console\Prompts\Screen;
use App\Console\Wizard\ConnectAssistant;
use App\Console\Wizard\Scopes\CommandScope;
use App\Console\Wizard\Section;
use App\Console\Wizard\TokenManager;
use App\Mcp\ToolExposure;
use App\Mcp\ToolRegistry;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Throwable;

use function Laravel\Prompts\select;

/**
 * Tokens for AI assistants, and which commands each may call.
 *
 * Two scopes, and the difference between them is the thing an operator most
 * often has backwards. **Global scope** is what this engine offers at all, in
 * `.env`; widening it is the only way to make a command reachable by anybody.
 * **Per-token scope** is one assistant's share of that; narrowing it is the
 * only way to keep a command from one assistant without keeping it from
 * everyone. Side by side, with both stated in the header, is what makes that
 * legible.
 */
class McpTokensSection implements Section
{
    /** @var array<int, string> */
    private array $receipt = [];

    public static function key(): string
    {
        return 'mcp-tokens';
    }

    public static function label(): string
    {
        return 'MCP tokens — for AI assistants';
    }

    public static function hint(): string
    {
        return "What this engine offers assistants, and each assistant's share of it.";
    }

    /** @return array<int, string> */
    public function receipt(): array
    {
        return $this->receipt;
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $current = ToolExposure::current();

            Screen::draw(self::label(), sprintf(
                "Global scope:    %d of %d commands, ceiling %s.\n"
                . 'Tokens:          %s.',
                count($current->exposed()),
                count(ToolRegistry::all()),
                $current->policy()->mode(),
                $this->tokenSummary($current),
            ));

            $choice = (string) select(
                label: 'What would you like to change?',
                options: [
                    'connect' => 'Connect an assistant — mint a token and print its setup command',
                    'global' => 'Global scope         — what this engine offers assistants at all',
                    'tokens' => 'Tokens               — limit what one may call, revoke, delete',
                    'back' => 'Back',
                ],
                // The first thing anyone does on a new engine, and the first
                // row for that reason.
                default: 'connect',
                scroll: 4,
                hint: 'A token can only be given what the global scope offers.',
            );

            if ($choice === 'connect') {
                $connect = new ConnectAssistant(self::label(), $dryRun);
                $connect->run();
                $this->receipt = array_merge($this->receipt, $connect->receipt());

                continue;
            }

            if ($choice === 'global') {
                $section = new McpGlobalScopeSection();
                $code = $section->run($dryRun);
                $this->receipt = array_merge($this->receipt, $section->receipt());

                if ($code !== 0) {
                    return $code;
                }

                continue;
            }

            if ($choice === 'tokens') {
                $manager = new TokenManager(
                    title: self::label(),
                    dryRun: $dryRun,
                    ability: TokenAbilities::MCP,
                    scope: new CommandScope(),
                    noun: 'an assistant',
                    narrow: 'Change which commands it may call…',
                    mintHint: '`pae connect` prints the whole setup command for a given assistant.',
                );

                $manager->run();
                $this->receipt = array_merge($this->receipt, $manager->receipt());

                continue;
            }

            return 0;
        }
    }

    /** How much narrowing the tokens have had, in a phrase. */
    private function tokenSummary(ToolExposure $current): string
    {
        try {
            $offered = $current->exposedNames();

            $tokens = Admin::rootAccount()->tokens()->get()
                ->filter(fn (PersonalAccessToken $t): bool => TokenAbilities::of($t)->mayUseMcp());

            if ($tokens->isEmpty()) {
                return 'none yet';
            }

            $limited = $tokens->filter(
                fn (PersonalAccessToken $t): bool => TokenAbilities::of($t)->commands() !== null
            );

            return $limited->isEmpty()
                ? sprintf('%d, none limited', $tokens->count())
                : sprintf(
                    '%d, %d limited (smallest: %d of %d commands)',
                    $tokens->count(),
                    $limited->count(),
                    $limited->map(fn (PersonalAccessToken $t): int => count(array_intersect(
                        TokenAbilities::of($t)->commands() ?? [],
                        $offered
                    )))->min(),
                    count($offered),
                );
        } catch (Throwable) {
            // The header is not the place to report a database that is down;
            // choosing Tokens says so properly.
            return 'unknown — the database could not be read';
        }
    }
}
