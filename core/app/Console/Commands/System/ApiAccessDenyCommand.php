<?php

namespace App\Console\Commands\System;

use App\Lib\Project\ApiAccess;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Takes the allowance away: the token is revoked at once and the project's
 * containers lose their way to the API port. The environment variables leave
 * the running application on the apply this queues.
 */
class ApiAccessDenyCommand extends Command
{
    protected $signature = 'system:api-access:deny {project : The project to deny}';

    protected $description = 'Revoke a project\'s Engine API token and stop its containers reaching the API';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        if (User::findByUsername($username) === null) {
            $this->error("There is no project '{$username}' on this engine.");

            return 1;
        }

        if (!(new ApiAccess())->deny($username)) {
            $this->line("{$username} was not allowed to use the Engine API; nothing changed.");

            return 0;
        }

        $this->info("Denied {$username} the Engine API: its token is revoked, its firewall rule is gone, and "
            . 'the URL, token and certificate are taken out of the application now.');

        return 0;
    }
}
