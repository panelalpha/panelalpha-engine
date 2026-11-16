<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * Reads a Railpack build plan without rewriting it: its runtime-install layer is keyed on the project's own
 * version files, and there is no shared layer cache left to share it with.
 */
class RailpackPlan
{
    /**
     * Every image the plan names, found by walking the whole document for `image` keys since railpack moves
     * them between releases; anything docker would not accept is dropped, as this feeds a pull.
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
