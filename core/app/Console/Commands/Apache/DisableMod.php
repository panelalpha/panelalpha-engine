<?php

namespace App\Console\Commands\Apache;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Models\User;

class DisableMod extends ApacheModCommand
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['apache:disable-mod'];

    protected $signature = 'apache:mod:disable {mod}' . ProjectOptions::SIGNATURE;

    protected $description = 'Disable an Apache module for a project (or --all projects)';

    protected function doing(): string
    {
        return 'Disabling';
    }

    protected function done(): string
    {
        return 'disabled';
    }

    protected function applyMod(User $user, string $mod): void
    {
        $user->project()->disableApacheMod($mod);
    }
}
