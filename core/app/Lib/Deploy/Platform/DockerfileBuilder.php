<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use App\Lib\Deploy\Platform\Dockerfile\CommandDockerfile;
use App\Lib\Deploy\Platform\Dockerfile\DockerfileWriter;
use App\Lib\Deploy\Platform\Dockerfile\NodeDockerfile;
use App\Lib\Deploy\Platform\Dockerfile\StaticSiteDockerfile;

/**
 * Picks the writer for a manifest's `runtime` and lets it fill in its stub.
 *
 * Named panelalpha.Dockerfile so the next detect pass still sees the platform
 * rather than mistaking this for a Dockerfile the customer wrote.
 *
 * No Laravel dependencies — unit-testable.
 */
final class DockerfileBuilder
{
    public const FILENAME = 'panelalpha.Dockerfile';

    /** @var array<string, class-string<DockerfileWriter>> */
    private const WRITERS = [
        PlatformManifest::RUNTIME_NGINX => StaticSiteDockerfile::class,
        PlatformManifest::RUNTIME_COMMAND => CommandDockerfile::class,
        PlatformManifest::RUNTIME_NODE => NodeDockerfile::class,
    ];

    /**
     * @param  array<string, mixed>  $recipe
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public static function generate(array $recipe, array $files, string $projectDir = ''): string
    {
        return self::writerFor(new BuildRecipe($recipe, $files, $projectDir))->render();
    }

    private static function writerFor(BuildRecipe $recipe): DockerfileWriter
    {
        $writer = self::WRITERS[$recipe->runtime] ?? NodeDockerfile::class;

        return new $writer($recipe);
    }
}
