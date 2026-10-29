<?php

namespace App\Console\Commands\Concerns;

/**
 * For a command that asks before it changes anything, unless --force is given.
 * Without a terminal confirm() answers no, so such a run used to print
 * "Cancelled." and exit 0 with nothing changed, which a script reads as done.
 */
trait ConfirmsChanges
{
    /**
     * True, with one line on stderr, when the change can be neither asked about
     * nor forced. Called before the command describes the change.
     */
    protected function refusedWithoutConfirmation(): bool
    {
        if ($this->option('force') || $this->input->isInteractive()) {
            return false;
        }

        $this->getOutput()->getErrorStyle()->writeln(
            '<error>This change needs confirmation: pass --force to apply it without a prompt.</error>'
        );

        return true;
    }
}
