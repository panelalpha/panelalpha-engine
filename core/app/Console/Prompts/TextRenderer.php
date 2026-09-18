<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Themes\Default\TextPromptRenderer;

/** The default free-text prompt, in the PanelAlpha orange. See {@see PanelAlphaTheme}. */
class TextRenderer extends TextPromptRenderer
{
    use PaintsOrange;
}
