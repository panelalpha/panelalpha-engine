<?php

namespace App\Console\Commands\Api;

use App\Models\Admin;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensDeleteCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['api-tokens:delete'];

    protected $signature = 'api:token:delete {id}';

    protected $description = 'Delete an API token by ID';

    public function handle(): int
    {
        $root = Admin::rootAccount();
        $id = $this->argument('id');
        if (! is_scalar($id)) {
            $this->error("Invalid 'id' argument");

            return 1;
        }
        /** @var ?PersonalAccessToken */
        $token = $root->tokens()->find($id);
        if (! $token) {
            $this->error("Invalid ID: {$id}");

            return 1;
        }

        $token->delete();
        $this->info("Api token #{$token->id} '{$token->name}' deleted.");

        return 0;
    }
}
