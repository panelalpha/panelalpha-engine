<?php

namespace App\Console\Commands\Api;

use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;

class McpTokensDeleteCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp-tokens:delete'];

    protected $signature = 'mcp:token:delete {id}';

    protected $description = 'Permanently delete an MCP token by ID';

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

        $token->delete();
        $this->info("Token #{$token->id} '{$token->name}' deleted.");

        return 0;
    }
}
