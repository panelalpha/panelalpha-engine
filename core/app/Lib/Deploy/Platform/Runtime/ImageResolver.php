<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Turns resolved requirements into the image the application runs in. A
 * ROLE_BUILD requirement gets its own build stage; ROLE_RUNTIME must be in the
 * final image, and two of those need a composite named by compositeTag().
 */
final class ImageResolver
{
    public const COMPOSITE_REPOSITORY = 'panelalpha/stack';

    /**
     * The base image for the application's runtime stage.
     *
     * @param  list<Requirement>  $requirements
     */
    public static function runtimeImage(array $requirements): ?string
    {
        $runtime = self::runtimeRoleRequirements($requirements);

        if ($runtime === []) {
            return null;
        }

        if (count($runtime) === 1) {
            return RuntimeRegistry::get($runtime[0]->id)->image($runtime[0]);
        }

        return self::compositeTag($runtime);
    }

    /**
     * Build-stage images, keyed by runtime id, in manifest order.
     *
     * @param  list<Requirement>  $requirements
     * @return array<string, string>
     */
    public static function buildImages(array $requirements): array
    {
        $images = [];
        foreach ($requirements as $requirement) {
            if ($requirement->isRuntime()) {
                continue;
            }
            $images[$requirement->id] = RuntimeRegistry::get($requirement->id)->image($requirement);
        }

        return $images;
    }

    /**
     * A deterministic name for a multi-runtime image,
     * `panelalpha/stack:node-20_php-8.3-pa<fingerprint>`: readable half, plus a
     * fingerprint so a changed assembly produces a new tag instead of serving a
     * stale image.
     *
     * @param  list<Requirement>  $requirements
     */
    public static function compositeTag(array $requirements): string
    {
        $token = Requirement::setToken($requirements);

        return self::COMPOSITE_REPOSITORY.':'.$token.'-pa'.self::fingerprint($token);
    }

    /**
     * @param  list<Requirement>  $requirements
     * @return list<Requirement>
     */
    public static function runtimeRoleRequirements(array $requirements): array
    {
        return array_values(array_filter(
            $requirements,
            static fn (Requirement $r): bool => $r->isRuntime()
        ));
    }

    /**
     * Whether this requirement set needs an image nobody publishes.
     *
     * @param  list<Requirement>  $requirements
     */
    public static function needsComposite(array $requirements): bool
    {
        return count(self::runtimeRoleRequirements($requirements)) > 1;
    }

    private static function fingerprint(string $token): string
    {
        // Version the assembly recipe, not just the inputs.
        return substr(sha1('v1 '.$token), 0, 8);
    }
}
