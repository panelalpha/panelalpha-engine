<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;

class FixFilePermissions extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:fix-file-permissions', 'users:fix-file-permissions'];

    protected $signature = 'project:permission:fix' . ProjectOptions::SIGNATURE;

    protected $description = 'Fix file permissions under a project\'s home directory';

    protected function progress(User $user): string
    {
        return "Fixing '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $user->project()->fixPermissions();
    }
}
