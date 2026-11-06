<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingException;

/** A recipe asked for by id that this engine does not ship. */
final class UnknownRecipeException extends ManifestException implements FindingException
{
    public const CODE = 'recipe_unknown';

    public function __construct(public readonly string $recipe)
    {
        parent::__construct("No recipe called '{$recipe}'. Inspect the source to see which recipes can deploy it.");
    }

    public function finding(): Finding
    {
        return new Finding(self::CODE, $this->getMessage(), ['recipe' => $this->recipe]);
    }
}
