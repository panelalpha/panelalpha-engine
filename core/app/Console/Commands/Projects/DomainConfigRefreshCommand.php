<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting;

/**
 * Rewrites each PHP hosting domain's Apache vhost file, otherwise written only on domain create or rebuild, and
 * reloads Apache. Issues no certificate and leaves container projects and the host webserver alone. Safe to run again.
 */
class DomainConfigRefreshCommand extends ProjectFleetCommand
{
    protected $signature = 'projects:domain-config:refresh'.ProjectOptions::SIGNATURE;

    protected $description = 'Write the in-project webserver config of existing PHP hosting projects\' domains again from the current template';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Refreshing the domain config of '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof PhpHosting) {
            $this->outcome = '  Not a PHP hosting project; nothing to refresh.';

            return;
        }
        if ($runtime->fpmApache() === null) {
            $this->outcome = '  Its domains are served by the host webserver; nothing to refresh.';

            return;
        }

        foreach ($user->getDomains() as $domain) {
            $runtime->createDomainConfig($domain);
        }
        $runtime->reloadWebserver();
        $this->outcome = '  '.count($user->getDomains()).' domain config(s) written.';
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
