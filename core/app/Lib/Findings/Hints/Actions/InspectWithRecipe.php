<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** The same source inspected with another recipe: a repository with the branch and directory read, or the project's name. */
final class InspectWithRecipe extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::refusals();
    }

    public function rest(): string
    {
        return 'POST /source/inspect';
    }

    public function params(): array
    {
        return ['source' => '{source}', 'branch' => '{branch}', 'subdirectory' => '{subdirectory}', 'recipe' => null];
    }

    public function optional(): array
    {
        return ['branch', 'subdirectory'];
    }
}
