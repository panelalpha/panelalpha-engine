<?php

namespace App\Console\Commands\Api;

use App\Auth\TokenAbilities;
use App\Mcp\ToolExposure;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;

class McpTokensListCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp-tokens:list'];

    protected $signature = 'mcp:token:list';

    protected $description = 'List all MCP tokens for the root admin';

    public function handle(): int
    {
        // Every token, filtered in PHP by the one class that knows the
        // vocabulary — not a `whereJsonContains('abilities','mcp')` with an
        // `orWhereNull` bolted on, which is what this and the HTTP endpoint
        // each had their own version of, disagreeing about legacy tokens.
        $tokens = Admin::rootAccount()
            ->tokens()
            ->orderBy('id')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'])
            ->filter(fn (PersonalAccessToken $t): bool => TokenAbilities::of($t)->mayUseMcp())
            ->map(fn (PersonalAccessToken $t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'kind'         => TokenAbilities::of($t)->label(),
                // What this token may call, which is not the same question as
                // what the engine offers. `pae configure tokens` sets it.
                'commands'     => $this->commands($t),
                'last_used_at' => $t->last_used_at ?? 'never',
                'expires_at'   => $t->expires_at ?? 'never',
                'revoked_at'   => $t->revoked_at ?? '-',
                'created_at'   => $t->created_at,
            ])
            ->values()
            ->toArray();

        $this->table(
            ['ID', 'Name', 'Kind', 'Commands', 'Last Used', 'Expires', 'Revoked At', 'Created'],
            $tokens
        );

        return 0;
    }

    /** How far this token's own abilities narrow the engine's command list. */
    private function commands(PersonalAccessToken $token): string
    {
        $names = TokenAbilities::of($token)->commands();

        if ($names === null) {
            return 'all';
        }

        $offered = ToolExposure::current()->exposedNames();

        return sprintf('%d of %d', count(array_intersect($names, $offered)), count($offered));
    }
}
