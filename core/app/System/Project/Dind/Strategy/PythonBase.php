<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\HostCompile;

/**
 * Swaps a Python recipe's stock image for the shared base that can build its
 * dependencies.
 *
 * One class because there are two callers and they must not disagree.
 * {@see FrameworkStrategy} writes the image into the compose file;
 * {@see HostCompile} picks the image the
 * `.venv` is built in, re-running detection to get there, so it sees nothing
 * the strategy decided. Two answers to "which Python" is how a venv gets
 * compiled against headers the runtime does not have.
 *
 * Safe to call for any command runtime: a Go, Rust or Java image passes
 * straight through.
 */
final class PythonBase
{
    /** @var list<string> */
    private const MANIFESTS = ['requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py'];

    /**
     * The shared base when the host can provide one, the image the recipe
     * resolved otherwise.
     */
    public static function imageFor(DindProject $dind, string $projectDir, string $image): string
    {
        $image = trim($image);
        // Decline before touching the filesystem or the inner daemon: this runs
        // for every command runtime, and PythonBaseImage::tag() answers null
        // for anything that is not a plain official `python:` tag.
        if ($image === '' || PythonBaseImage::tag($image, ['build-essential']) === null) {
            return $image;
        }

        $packages = SystemPackages::for(self::manifests($dind, $projectDir));

        // Null means the base could not be provided — build failed, disk full.
        // The stock image still installs every dependency that ships a wheel,
        // so a pip error naming the missing header beats a deploy declined
        // before it started.
        return $dind->innerDocker()->ensurePythonBaseImage($image, $packages) ?: $image;
    }

    /**
     * @return list<string>
     */
    private static function manifests(DindProject $dind, string $projectDir): array
    {
        $contents = [];
        foreach (self::MANIFESTS as $name) {
            $body = $dind->projectTree()->readIn($projectDir, $name);
            if (is_string($body) && $body !== '') {
                $contents[] = $body;
            }
        }

        return $contents;
    }
}
