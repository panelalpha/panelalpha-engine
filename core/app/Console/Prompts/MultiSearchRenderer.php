<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Themes\Default\MultiSearchPromptRenderer;

/** The default filterable multi-select prompt, in the PanelAlpha orange. See {@see PanelAlphaTheme}. */
class MultiSearchRenderer extends MultiSearchPromptRenderer
{
    use PaintsOrange;
}
