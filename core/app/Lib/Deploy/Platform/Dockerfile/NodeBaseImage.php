<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;

/**
 * The image a JS build runs in: bun's own when the project locks to bun,
 * otherwise the Node the project's `engines` field asks for.
 */
final class NodeBaseImage
{
    private const BUN = 'bun';

    private const NODE_PREFIX = 'node:';

    public static function for(BuildRecipe $recipe): string
    {
        return $recipe->packageManager === self::BUN
            ? HostNodeBuild::BUN_IMAGE
            : self::node($recipe);
    }

    /**
     * The recipe's `node:` tag in the variant this project needs (full when its install compiles
     * a dependency); without one, the image is resolved from the project.
     */
    private static function node(BuildRecipe $recipe): string
    {
        $declared = self::declared($recipe);
        if ($declared === null) {
            return self::detected($recipe->projectDir);
        }

        return Images::nodeBuildImage($declared, $recipe->projectDir);
    }

    private static function declared(BuildRecipe $recipe): ?string
    {
        return str_starts_with($recipe->image, self::NODE_PREFIX) ? $recipe->image : null;
    }

    private static function detected(string $projectDir): string
    {
        return NodeRuntime::imageFor($projectDir);
    }
}
