<?php

namespace App\Console\Prompts;

use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;

/**
 * The engine's prompt theme: the default one, in the PanelAlpha orange.
 *
 * This class is both the theme's registrar and its select renderer, because
 * select was the first prompt the engine asked (`pae connect`). The colour
 * itself lives in {@see PaintsOrange}, which every renderer in this namespace
 * shares, so a prompt type is themed by adding six lines rather than by
 * restating the palette.
 *
 * Register every type the CLI uses rather than only the one in front of you: a
 * wizard that asks a themed question and then an unthemed one looks broken,
 * and the prompt types not listed here simply stay as the package drew them.
 */
class PanelAlphaTheme extends SelectPromptRenderer
{
    use PaintsOrange;

    /** The name it is registered under; `default` is the one name that cannot be taken. */
    public const NAME = 'panelalpha';

    /**
     * Register the theme and make it active.
     *
     * Must run before a prompt is constructed: the renderer is resolved in the
     * prompt's constructor, from the exact class being built, so a theme added
     * afterwards changes nothing about a prompt that already exists.
     */
    public static function register(): void
    {
        Prompt::addTheme(self::NAME, [
            SelectPrompt::class => self::class,
            MultiSelectPrompt::class => MultiSelectRenderer::class,
            MultiSearchPrompt::class => MultiSearchRenderer::class,
            ConfirmPrompt::class => ConfirmRenderer::class,
            TextPrompt::class => TextRenderer::class,
        ]);

        Prompt::theme(self::NAME);
    }
}
