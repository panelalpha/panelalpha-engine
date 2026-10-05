<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * The images an account needs before a Railpack build runs in it.
 *
 * Read out of the plan railpack just wrote, not listed anywhere: those tags
 * follow Railpack's own release calendar, and a hand-kept list goes stale
 * silently — the account loads the tags it names and the build pulls the ones
 * it wants through the nested NAT.
 */
class RailpackCache
{
    /**
     * The BuildKit frontend that reads a railpack plan. The one image the
     * engine names itself: it goes in as a `BUILDKIT_SYNTAX` build-arg, so the
     * plan never mentions it and it cannot be derived like the rest.
     */
    public const FRONTEND_IMAGE = 'ghcr.io/railwayapp/railpack-frontend:latest';

    /** Where Railpack publishes its frontend, builder and runtime images. */
    private const REPOSITORY_PREFIX = 'ghcr.io/railwayapp/railpack-';

    /**
     * Whether $ref is one of Railpack's own images. A deploy fetches those
     * onto the host under whatever tag its plan names, which no catalogue can
     * list ahead of time.
     */
    public static function isRailpackImage(string $ref): bool
    {
        return str_starts_with($ref, self::REPOSITORY_PREFIX);
    }

    /**
     * Images to have in the account before the build runs: the frontend plus
     * whatever the plan names as a stage base.
     *
     * @return list<string>
     */
    public static function preloadImages(string $planJson): array
    {
        return array_values(array_unique(
            array_merge([self::FRONTEND_IMAGE], RailpackPlan::imageRefs($planJson))
        ));
    }
}
