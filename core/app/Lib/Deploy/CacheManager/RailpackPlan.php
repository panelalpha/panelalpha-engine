<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * Reading a Railpack build plan.
 *
 * Read, not rewritten: railpack's `packages:mise` step copies the project's
 * version files into the image before `mise install`, so BuildKit keys the
 * runtime-install layer on the customer's own files and no two accounts share
 * it. Nothing here strips those copies any more — there is no shared layer
 * cache left to share the layer with.
 */
class RailpackPlan
{
    /**
     * Every image the plan names.
     *
     * They appear in two shapes — `steps[].inputs[].image` for a build stage
     * and `deploy.base.image` for the runtime — and railpack has moved both
     * between releases, so the whole document is walked for an `image` key
     * instead of reading two paths. Anything docker would not accept is
     * dropped: this feeds a pull.
     *
     * @return list<string>
     */
    public static function imageRefs(string $planJson): array
    {
        $plan = json_decode($planJson, true);
        if (! is_array($plan)) {
            return [];
        }

        $refs = [];
        self::collectImages($plan, $refs);

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $refs
     */
    private static function collectImages(mixed $node, array &$refs): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'image' && is_string($value) && ImageTransfer::isSafeImageRef($value)) {
                $refs[] = $value;

                continue;
            }
            self::collectImages($value, $refs);
        }
    }
}
