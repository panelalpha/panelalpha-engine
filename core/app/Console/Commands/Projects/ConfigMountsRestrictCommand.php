<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\ReadOnlyConfigMounts;

/**
 * Makes the engine's config mounts read-only in each PHP hosting project's compose file ({@see ReadOnlyConfigMounts}).
 * They take effect when the container is next recreated, or at once with --recreate. Safe to run again.
 */
class ConfigMountsRestrictCommand extends ProjectFleetCommand
{
    protected $signature = 'projects:config-mounts:restrict'.ProjectOptions::SIGNATURE
        .' {--recreate : Recreate each running project\'s container now if its mounts changed, which restarts its site}';

    protected $description = 'Mount the engine\'s own config read-only in existing PHP hosting projects; each takes effect when its container is recreated';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Restricting the config mounts of '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $project = $user->project(app(System::class));
        $runtime = $project->runtime();
        if (! $runtime instanceof PhpHosting) {
            $this->outcome = '  Not a PHP hosting project; nothing to restrict.';

            return;
        }

        $changed = (new ReadOnlyConfigMounts)->apply($runtime);
        if ($this->option('recreate') && $project->isRunning()) {
            $project->up();
            $this->outcome = '  Read-only; container brought up to date.';

            return;
        }
        $this->outcome = $changed ? '  Read-only from the container\'s next recreate.' : '  Already read-only.';
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
