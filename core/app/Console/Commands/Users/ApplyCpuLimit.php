<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;
use App\System\Project\Dind;

/**
 * A container project's CPU limit changed after it was created reached neither its compose file nor its running
 * container. This puts the stored limit on both. Safe to run again: a limit already in place changes nothing.
 */
class ApplyCpuLimit extends ProjectFleetCommand
{
    protected $signature = 'project:cpu-limit:apply'.ProjectOptions::SIGNATURE;

    protected $description = 'Put a container project\'s stored CPU limit on its account container';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Applying the CPU limit of '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof Dind || ! $runtime->exists()) {
            $this->outcome = '  Not a container project with an account; nothing to apply.';

            return;
        }

        $runtime->applyCpuLimit();
        $this->outcome = '  Applied.';
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
