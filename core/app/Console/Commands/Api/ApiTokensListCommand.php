<?php

namespace App\Console\Commands\Api;

use App\Models\Admin;
use Illuminate\Console\Command;

class ApiTokensListCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['api-tokens:list'];

    protected $signature = 'api:token:list';

    protected $description = 'List all API tokens for the root admin';

    public function handle(): int
    {
        $root = Admin::rootAccount();
        $tokens = $root->tokens()->get(['id', 'name', 'last_used_at', 'created_at'])->toArray();
        $this->table(
            ['ID', 'Name', 'Last Used', 'Created'],
            $tokens
        );

        return 0;
    }
}
