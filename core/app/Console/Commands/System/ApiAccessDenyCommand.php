<?php

namespace App\Console\Commands\System;

use App\Lib\Project\ApiAccess;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Takes the allowance away: every token the project holds is revoked at once and
 * its containers lose their way to the API port. The environment variables leave
 * the running application on the apply this queues.
 */
class ApiAccessDenyCommand extends Command
{
    protected $signature = 'system:api-access:deny {project : The project to deny}';

    protected $description = 'Revoke a project\'s Engine API tokens and stop its containers reaching the API';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        if (User::findByUsername($username) === null) {
            $this->error("There is no project '{$username}' on this engine.");

            return 1;
        }

        $hadToken = ApiAccess::hasValidToken($username);

        if ((new ApiAccess)->deny($username)) {
            $this->info("Denied {$username} the Engine API: its tokens are revoked, its firewall rule is gone, and "
                .'the URL, token and certificate are taken out of the application now.');

            return 0;
        }

        if ($hadToken) {
            $this->info("{$username} was not allowed to use the Engine API, but a token an earlier allowance left valid is revoked now.");

            return 0;
        }

        $this->line("{$username} was not allowed to use the Engine API; nothing changed.");

        return 0;
    }
}
