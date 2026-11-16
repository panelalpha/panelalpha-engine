<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** The base images this engine builds, built again ahead of deploys; an operator's. */
final class PrewarmImages extends Hint
{
    public function resolves(): array
    {
        return ['base-image-unavailable', 'base-image-rebuild-failed'];
    }

    public function cli(): string
    {
        return 'system:image:prewarm';
    }
}
