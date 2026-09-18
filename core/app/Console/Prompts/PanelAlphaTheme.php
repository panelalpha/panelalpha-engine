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
 * Both registrar and select renderer, select being the first prompt the engine
 * asked. Unregistered prompt types stay as the package drew them, which looks
 * broken next to a themed one — so register every type the CLI uses.
 */
class PanelAlphaTheme extends SelectPromptRenderer
{
    use PaintsOrange;

    public const NAME = 'panelalpha';

    /**
     * Must run before a prompt is constructed: the renderer is resolved in the
     * prompt's constructor, so registering later changes nothing.
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
