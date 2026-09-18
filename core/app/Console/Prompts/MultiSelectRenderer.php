<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Themes\Default\MultiSelectPromptRenderer;

/** The default multi-select prompt, in the PanelAlpha orange. See {@see PanelAlphaTheme}. */
class MultiSelectRenderer extends MultiSelectPromptRenderer
{
    use PaintsOrange;
}
