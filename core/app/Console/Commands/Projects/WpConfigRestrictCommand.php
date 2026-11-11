<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\WpConfigRestriction;

/**
 * Applies {@see WpConfigRestriction} to every PHP hosting project, where an unrestricted wp-config.php is readable by
 * every other account. Safe to run again.
 */
class WpConfigRestrictCommand extends ProjectFleetCommand
{
    protected $signature = 'projects:wp-config:restrict'.ProjectOptions::SIGNATURE;

    protected $description = 'Make the wp-config.php of existing PHP hosting projects readable by the project\'s own user only';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Restricting the wp-config.php files of '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof PhpHosting) {
            $this->outcome = '  Not a PHP hosting project; nothing to restrict.';

            return;
        }

        $restricted = (new WpConfigRestriction($runtime))->apply();
        $this->outcome = "  {$restricted} wp-config.php file(s) readable by the project's own user only.";
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
