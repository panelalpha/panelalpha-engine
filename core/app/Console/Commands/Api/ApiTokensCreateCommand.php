<?php

namespace App\Console\Commands\Api;

use App\Auth\TokenAbilities;
use App\Console\Commands\Concerns\MintsTokens;
use App\Models\Admin;
use Illuminate\Console\Command;

class ApiTokensCreateCommand extends Command
{
    use MintsTokens;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['api-tokens:create'];

    protected $signature = 'api:token:create
                            {name}
                            {--s|short}
                            {--mcp : Also let this token speak MCP}
                            {--expires= : How long it lasts, e.g. 90d, 12h; omit for never}';

    protected $description = 'Create an API token for your own software';

    public function handle(): int
    {
        $root = Admin::rootAccount();
        $name = $this->argument('name');
        if (!is_string($name)) {
            $this->error("Invalid 'name' argument");
            return 1;
        }
        $expiry = $this->expiry();

        if ($expiry === false) {
            return 1;
        }

        // `api`, not Sanctum's `*`. The difference is the point: this token
        // may call the REST API, and unless --mcp is given it is refused at
        // /mcp the way an assistant's token is refused at /api.
        $token = $root->createToken(
            $name,
            TokenAbilities::build(api: true, mcp: (bool) $this->option('mcp')),
            $expiry
        );

        if ($this->option('short')) {
            $this->line($token->plainTextToken);
            return 0;
        }

        $this->info(sprintf('Api token created (%s).', $this->lifetime($expiry)));
        $this->warn('Save it, it won\'t be retrieveable again.');
        $this->comment('=============================');
        $this->line($token->plainTextToken);
        $this->comment('=============================');
        return 0;
    }
}