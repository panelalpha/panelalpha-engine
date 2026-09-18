<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

/**
 * One screen at a time.
 *
 * A prompt on its own scrolls: the package writes each frame under the last,
 * which is right for a command that asks a question and answers it. A wizard
 * is not that. An operator moving between menus produces a transcript of their
 * own navigation, and the thing they are looking at ends up below the fold
 * while everything they have already dealt with stays above it.
 *
 * So every screen here is drawn from the top. {@see draw()} clears, puts the
 * title back, and restates where the operator is, so what is on the terminal
 * is the current step and nothing else.
 *
 * What survives that is the wizard's *result* — see `Section::receipt()`,
 * which is printed once, at the end, onto a screen nothing clears afterwards.
 * Clearing erases rather than scrolls, so anything not in the receipt is gone
 * for good, and that is the reason the receipt exists.
 */
class Screen
{
    /** The terminal the wizard was started on. See {@see restore()}. */
    private static ?OutputInterface $terminal = null;

    /**
     * Remember where the wizard is drawing, once, at the start.
     *
     * Running another artisan command from inside this one takes the screen
     * away: `Illuminate\Console\Concerns\ConfiguresPrompts` calls
     * `Prompt::setOutput($this->output)` for the command it starts, pointing
     * every later prompt at that command's buffered output — and never puts it
     * back. Everything afterwards still runs, and none of it is seen. A
     * setting changes, the screen sits unchanged, and a `pause()` waits for a
     * key nobody knows to press.
     */
    public static function remember(OutputInterface $output): void
    {
        self::$terminal = $output;
    }

    /** Put the screen back after something else has taken it. */
    public static function restore(): void
    {
        if (self::$terminal !== null) {
            Prompt::setOutput(self::$terminal);
        }
    }

    /**
     * Clear the terminal and draw a screen's standing head.
     *
     * @param string $title what the operator is configuring
     * @param string $body  where they are in it; blank for none
     */
    public static function draw(string $title, string $body = ''): void
    {
        clear();
        intro($title);

        if (trim($body) !== '') {
            note($body);
        }
    }

    /** Clear without drawing anything: the last screen before the result. */
    public static function wipe(): void
    {
        clear();
    }
}
