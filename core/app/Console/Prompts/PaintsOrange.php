<?php

namespace App\Console\Prompts;

/** The frame and accent colour shared by every renderer the engine themes. */
trait PaintsOrange
{
    // 215, not #ffa500: Symfony degrades hex to plain yellow unless the
    // terminal advertises COLORTERM=truecolor, which most do not.
    private const ORANGE = "\e[38;5;215m";

    /**
     * Orange wherever the default renderer asks for gray. Red (cancel) and
     * yellow (error) mean something, so they pass through.
     *
     * An answered prompt draws nothing: `pae configure` is a menu the operator
     * returns to, and settled frames pile up into a transcript of their
     * navigation. `Prompt::render()` erases the previous frame first, so
     * returning early leaves the prompt redrawing in one place.
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

    /** Drops the newline the package appends to a submitted frame, too. */
    public function __toString()
    {
        return $this->prompt->state === 'submit' ? '' : parent::__toString();
    }

    /** The accent: label, highlighted row, scrollbar handle. */
    public function cyan(string $text): string
    {
        return $this->orange($text);
    }

    /** The method `box()` looks for when asked for an orange frame. */
    public function orange(string $text): string
    {
        return self::ORANGE . $text . "\e[39m";
    }
}
