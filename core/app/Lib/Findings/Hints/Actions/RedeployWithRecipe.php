<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** The project deployed again with another recipe. */
final class RedeployWithRecipe extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::refusals();
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/rebuild';
    }

    public function params(): array
    {
        return ['recipe' => null];
    }
}
