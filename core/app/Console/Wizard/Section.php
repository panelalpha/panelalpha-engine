<?php

namespace App\Console\Wizard;

/**
 * One area of the engine that `pae configure` can walk an operator through.
 *
 * A section owns its whole conversation — what to ask, in what order, and what
 * to write when the answers are confirmed — so adding one to
 * {@see Wizard::SECTIONS} is all it takes to put it on the menu.
 *
 * A section asks nothing it cannot act on and writes nothing it has not shown
 * the operator first: the last step is always a review the operator confirms.
 */
interface Section
{
    /** How the section is named on the command line: `pae configure mcp`. */
    public static function key(): string;

    /** The line the picker shows. */
    public static function label(): string;

    /** What the section changes, in one line, under that label. */
    public static function hint(): string;

    /**
     * Run the conversation.
     *
     * @param bool $dryRun show what would be written, then stop short of writing it
     * @return int the command's exit code
     */
    public function run(bool $dryRun): int;

    /**
     * What this section did, in the few lines worth keeping.
     *
     * Every screen is drawn over the last one ({@see \App\Console\Prompts\Screen}),
     * and clearing erases rather than scrolls — so anything the operator should
     * still have in their terminal once the wizard exits has to be said again
     * here. `pae configure` prints it on the way out, onto a screen nothing
     * clears afterwards. Empty when the section changed nothing.
     *
     * @return array<int, string>
     */
    public function receipt(): array;
}
