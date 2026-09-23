<?php

namespace App\Console\Wizard;

/** One area of the engine that `pae configure` can walk an operator through. */
interface Section
{
    /** How the section is named on the command line: `pae configure mcp`. */
    public static function key(): string;

    public static function label(): string;

    /** What the section changes, in one line, under that label. */
    public static function hint(): string;

    /** @param bool $dryRun show what would be written, then stop short of writing it */
    public function run(bool $dryRun): int;

    /**
     * What this section did. Printed on the way out, onto a screen nothing
     * clears — every other screen is drawn over the last. Empty when nothing changed.
     *
     * @return array<int, string>
     */
    public function receipt(): array;
}
