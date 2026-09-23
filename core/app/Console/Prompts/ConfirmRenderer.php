<?php

namespace App\Console\Prompts;

use Laravel\Prompts\Themes\Default\ConfirmPromptRenderer;

/** The default yes/no prompt, in the PanelAlpha orange. See {@see PanelAlphaTheme}. */
class ConfirmRenderer extends ConfirmPromptRenderer
{
    use PaintsOrange;
}
