<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\Php\ComposerManifest;
use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;

/**
 * PHP extensions a project requires that its image will not have, which fails the Composer install:
 * the php strategy has no per-project Dockerfile.
 */
final class PhpExtensionAvailability
{
    /**
     * Beyond the shared base and the variant it would build: past MAX_BAKED_EXTRAS none is baked at all.
     *
     * @return list<string> extension names, without the `ext-` prefix
     */
    public static function missing(?string $composerJson, ?string $composerLock = null): array
    {
        if ($composerJson === null || trim($composerJson) === '') {
            return [];
        }

        $required = PhpExtensions::for(new ComposerManifest($composerJson, $composerLock));
        if ($required === []) {
            return [];
        }

        $beyondBase = PhpBaseImage::missingExtensions($required);
        $wouldBake = PhpBaseImage::bakeableExtras($beyondBase);

        $missing = array_values(array_diff($beyondBase, $wouldBake));
        sort($missing);

        return $missing;
    }

    /** One sentence for a report, or null when nothing is missing. */
    public static function issue(?string $composerJson, ?string $composerLock = null): ?string
    {
        $missing = self::missing($composerJson, $composerLock);
        if ($missing === []) {
            return null;
        }

        $names = implode(', ', array_map(static fn (string $e): string => 'ext-'.$e, $missing));

        return count($missing) === 1
            ? "This project requires the PHP extension {$names}, which is not in the image it would run on."
            : "This project requires PHP extensions that are not in the image it would run on: {$names}.";
    }
}
