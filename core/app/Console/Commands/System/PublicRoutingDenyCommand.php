<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\PublicRoutingKinds;
use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Takes back what `system:public-routing:allow` gave, at once: the ports close and the project leaves the
 * server's own name.
 */
class PublicRoutingDenyCommand extends Command
{
    protected $signature = 'system:public-routing:deny
        {project : The project to deny}
        {--ports : Only the host ports}
        {--host-domain : Only the server\'s own name}';

    protected $description = "Take back a project's public ports or the server's own name; both when neither is named";

    public function handle(ProjectPublicRouting $routing): int
    {
        $user = User::findByUsername((string) $this->argument('project'));
        if ($user === null) {
            $this->error("There is no project '{$this->argument('project')}' on this engine.");

            return 1;
        }
        $kinds = PublicRoutingKinds::fromOptions((bool) $this->option('ports'), (bool) $this->option('host-domain'));

        try {
            $result = $routing->deny($user, $kinds === [] ? PublicRoutingGrants::KINDS : $kinds);
        } catch (DeployAlreadyRunningException $e) {
            $this->error($e->getMessage().' Run this again once it has finished.');

            return 1;
        }

        if (! $result['changed']) {
            $this->info("Nothing changed: {$user->username} is allowed ".PublicRoutingKinds::label($result['allowed']).'.');

            return 0;
        }

        $this->info("{$user->username} is now allowed ".PublicRoutingKinds::label($result['allowed']).'.');
        if ($result['moved_to'] !== null) {
            $this->line("Its main domain is {$result['moved_to']}; redeploy it so the application uses that name.");
        }
        if ($result['https_closed'] !== []) {
            $this->line('HTTPS is back on 443 alone for '.implode(', ', $result['https_closed']).'; redeploy the project so the application uses that address.');
        }

        return 0;
    }
}
