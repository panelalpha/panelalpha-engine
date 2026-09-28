<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;

class RebuildQuotas extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:rebuild-quotas', 'users:rebuild-quotas'];

    protected $signature = 'project:quota:rebuild' . ProjectOptions::SIGNATURE;

    protected $description = 'Recreate filesystem quotas for a project';

    protected function progress(User $user): string
    {
        return "Rebuilding quota for user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        // setquota failed rather than threw: the base reports it and the run
        // exits non-zero, same as any other per-project failure.
        if (!$user->project()->configureQuota()) {
            throw new \RuntimeException(
                '  Not enforced: setquota failed, is quota on for the /home filesystem? See the log.'
            );
        }
    }
}
