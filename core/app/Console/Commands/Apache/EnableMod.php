<?php

namespace App\Console\Commands\Apache;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Models\User;

class EnableMod extends ApacheModCommand
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['apache:enable-mod'];

    protected $signature = 'apache:mod:enable {mod}' . ProjectOptions::SIGNATURE;

    protected $description = 'Enable an Apache module for a project (or --all projects)';

    protected function doing(): string
    {
        return 'Enabling';
    }

    protected function done(): string
    {
        return 'enabled';
    }

    protected function applyMod(User $user, string $mod): void
    {
        $user->project()->enableApacheMod($mod);
    }
}
