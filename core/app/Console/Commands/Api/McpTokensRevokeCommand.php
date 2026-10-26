<?php

namespace App\Console\Commands\Api;

use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;

class McpTokensRevokeCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp-tokens:revoke'];

    protected $signature = 'mcp:token:revoke {id}';

    protected $description = 'Revoke an MCP token by ID (keeps it visible but blocks usage)';

    public function handle(): int
    {
        $root = Admin::rootAccount();
        $id = $this->argument('id');

        /** @var ?PersonalAccessToken */
        $token = $root->tokens()->find($id);

        if (! $token) {
            $this->error("Token #{$id} not found.");

            return 1;
        }

        if ($token->revoked_at) {
            $this->warn("Token #{$token->id} '{$token->name}' is already revoked.");

            return 0;
        }

        $token->forceFill(['revoked_at' => now()])->save();
        $this->info("Token #{$token->id} '{$token->name}' revoked.");

        return 0;
    }
}
