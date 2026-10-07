<?php

namespace App\Console\Commands\Apache;

use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;

/**
 * Turn an Apache module on or off for one project or all of them.
 *
 * The two commands differed in one verb and one method call.
 */
abstract class ApacheModCommand extends ProjectFleetCommand
{
    /** `Enabling` or `Disabling`, as the progress line starts. */
    abstract protected function doing(): string;

    /** `enabled` or `disabled`, as the finished line ends. */
    abstract protected function done(): string;

    abstract protected function applyMod(User $user, string $mod): void;

    protected function mod(): string
    {
        return (string) $this->argument('mod');
    }

    protected function progress(User $user): string
    {
        return $this->doing() . " mod '{$this->mod()}' for user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $this->applyMod($user, $this->mod());
        $user->project()->reloadApache();
    }

    protected function finished(User $user): string
    {
        return '  Mod ' . $this->done() . '.';
    }
}
