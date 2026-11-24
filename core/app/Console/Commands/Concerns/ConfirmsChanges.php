<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Console\Input\StreamableInputInterface;

/** For a command that asks before it changes anything, and refuses without --force where it cannot ask. */
trait ConfirmsChanges
{
    /** True, with one line on stderr, when the change can be neither asked about nor forced; called before the command describes the change. */
    protected function refusedWithoutConfirmation(): bool
    {
        if ($this->option('force') || $this->canBeAsked()) {
            return false;
        }

        $this->getOutput()->getErrorStyle()->writeln(
            '<error>This change needs confirmation: pass --force to apply it without a prompt.</error>'
        );

        return true;
    }

    /** Whether a question can be answered here: Symfony keeps a run without -n interactive even with no terminal to read from. */
    protected function canBeAsked(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return $stream === null
            ? (defined('STDIN') && @stream_isatty(STDIN)) || $this->laravel->runningUnitTests()
            : @stream_isatty($stream);
    }
}
