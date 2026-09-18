<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

/**
 * One screen at a time: every step is drawn from the top, over the last.
 *
 * Clearing erases rather than scrolls, so anything the operator should still
 * have after the wizard exits belongs in `Section::receipt()`.
 */
class Screen
{
    private static ?OutputInterface $terminal = null;

    /**
     * Remember where the wizard draws, once, at the start.
     *
     * `Illuminate\Console\Concerns\ConfiguresPrompts` calls `Prompt::setOutput()`
     * for every command it starts and never puts it back, so anything run with
     * `Artisan::call()` leaves later prompts writing into a discarded buffer.
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

    /** @param string $body where the operator is in it; blank for none */
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
