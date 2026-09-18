<?php

namespace App\Console\Prompts;

/**
 * The two hooks that carry the whole look, shared by every renderer the engine
 * themes.
 *
 *  - `box()` paints the frame through a method named by the `$color` it is
 *    handed, so repainting the default gray is a method of this trait.
 *  - `cyan()` is a renderer's accent — the title, the markers of the
 *    highlighted row, the scrollbar handle, the typed answer. Nothing in a
 *    default prompt is cyan for any other reason, so one override moves them
 *    together.
 *
 * The dim states are left alone: dim is what says "not the row you are on".
 * Red for a cancelled prompt and yellow for a failed one say something too, so
 * both are passed through untouched.
 */
trait PaintsOrange
{
    /**
     * 256-colour orange — 215, the same one the installer's outro paints
     * `pae connect` with (scripts/installer.sh, `outro_c 215`).
     *
     * Written as a code rather than picked by capability because the trait this
     * sits beside only knows the 16 named colours, and orange among them does
     * not exist: Symfony's hex path would degrade `#ffa500` to plain yellow on
     * any terminal that does not advertise `COLORTERM=truecolor`, which is
     * most of them. 215 is drawn as orange wherever 256 colours are supported
     * at all.
     */
    private const ORANGE = "\e[38;5;215m";

    /**
     * Frame the box in orange wherever the default renderer would use gray —
     * and draw nothing at all once the prompt has been answered.
     *
     * The default state asks for gray, which is only a default: no meaning is
     * lost by repainting it. Cancel asks for red and error for yellow, and
     * those say something, so both are passed through untouched.
     *
     * **Submit draws nothing.** The package leaves a settled frame behind as a
     * record of what was answered, which suits a command that asks two
     * questions and gets on with it. `pae configure` is a menu an operator
     * comes back to, so the record is a transcript of how they navigated —
     * twenty-five lines of group checkboxes scrolling the menu off the top
     * every time they visit it. Erasing the frame instead leaves the prompt
     * redrawing in one place, under whatever the section printed as a header.
     *
     * `Prompt::render()` erases the previous frame before writing the new one,
     * so returning early here is enough: nothing is written where the box was,
     * and the next prompt starts on that line.
     */
    protected function box(
        string $title,
        string $body,
        string $footer = '',
        string $color = 'gray',
        string $info = '',
    ): self {
        if ($this->prompt->state === 'submit') {
            return $this;
        }

        return parent::box($title, $body, $footer, $color === 'gray' ? 'orange' : $color, $info);
    }

    /**
     * An answered prompt renders as nothing at all — not even the newline the
     * package ends a submitted frame with, which would otherwise leave a blank
     * line behind for every question answered and scroll the screen anyway.
     */
    public function __toString()
    {
        return $this->prompt->state === 'submit' ? '' : parent::__toString();
    }

    /**
     * The accent: the label, the markers of the highlighted row, the scrollbar
     * handle. Same colour as the frame, so the box reads as one piece.
     */
    public function cyan(string $text): string
    {
        return $this->orange($text);
    }

    /** The method `box()` looks for when it has been asked for an orange frame. */
    public function orange(string $text): string
    {
        return self::ORANGE . $text . "\e[39m";
    }
}
