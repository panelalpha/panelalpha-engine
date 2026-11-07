<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\HostCompile;

/**
 * Swaps a Python recipe's stock image for the shared base that can build its dependencies, one answer for
 * {@see FrameworkStrategy} and {@see HostCompile} alike; any other command runtime's image passes through.
 */
final class PythonBase
{
    /** @var list<string> */
    private const MANIFESTS = ['requirements.txt', 'pyproject.toml', 'Pipfile', 'setup.py'];

    /**
     * The shared base when the host can provide one, else the recipe's image, which still installs every wheel.
     * A tag that is not a plain official `python:` one is declined before touching the disk or the inner daemon.
     */
    public static function imageFor(DindProject $dind, string $projectDir, string $image): string
    {
        $image = trim($image);
        if ($image === '' || PythonBaseImage::tag($image, ['build-essential']) === null) {
            return $image;
        }

        $packages = SystemPackages::for(self::manifests($dind, $projectDir));

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
