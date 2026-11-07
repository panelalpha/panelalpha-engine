<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * The images an account needs before a Railpack build, read from the plan railpack just wrote: its tags
 * follow Railpack's own release calendar, so a hand-kept list would go stale.
 */
class RailpackCache
{
    /**
     * The BuildKit frontend that reads a railpack plan, the one image the engine names itself: it goes in as
     * `BUILDKIT_SYNTAX`, so no plan mentions it.
     */
    public const FRONTEND_IMAGE = 'ghcr.io/railwayapp/railpack-frontend:latest';

    /** Where Railpack publishes its frontend, builder and runtime images. */
    private const REPOSITORY_PREFIX = 'ghcr.io/railwayapp/railpack-';

    /**
     * Whether $ref is one of Railpack's own images, fetched under whatever tag a plan names, which no list holds.
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
