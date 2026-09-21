<?php

namespace App\Lib\Deploy;

/**
 * The name to give a project nobody named: the repository, else the domain's
 * first label, else the recipe, else a plain "app". Whether that name is free
 * is {@see \App\Lib\Helper::generateUsernameFrom()}'s question, not this one's.
 *
 * No Laravel dependencies -- unit-testable with a string.
 */
final class ProjectName
{
    /** Used when the request carries nothing to name the project after. */
    public const FALLBACK = 'app';

    public static function base(?string $gitRepo = null, ?string $domain = null, ?string $recipe = null): string
    {
        if (is_string($gitRepo) && trim($gitRepo) !== '') {
            $fromRepo = HostingUsername::fromGitRepo($gitRepo);
            if ($fromRepo !== null) {
                return $fromRepo;
            }
        }

        if (is_string($domain) && trim($domain) !== '') {
            $label = explode('.', trim($domain, " \t\n\r\0\x0B."), 2)[0];
            $fromDomain = HostingUsername::normalize($label);
            if ($fromDomain !== null) {
                return $fromDomain;
            }
        }

        if (is_string($recipe) && trim($recipe) !== '') {
            $fromRecipe = HostingUsername::normalize($recipe);
            if ($fromRecipe !== null) {
                return $fromRecipe;
            }
        }

        return self::FALLBACK;
    }
}
