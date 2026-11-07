<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;

/**
 * The images a copied run file names, got into this account's own store before `compose create` or `up` as a deploy fetches
 * them ({@see AppLauncher}); compose builds `build:` services, so only Railpack's, kept out of the shared registry, is rebuilt.
 */
final class CopiedImages
{
    public function __construct(private DindProject $project) {}

    public function provide(string $composePath): void
    {
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($composePath)) {
            return;
        }
        $compose = $fs->fileGetContents($composePath);
        $this->project->registryLogin()->during(function () use ($composePath, $compose): void {
            $this->buildRailpackImage($compose, dirname($composePath));
            $this->preload($composePath);
            $this->refuseMissing($composePath);
        });
    }

    /**
     * The image the account already has under the Railpack tag the run file at
     * $composePath names, held before {@see provide()} builds that tag again.
     */
    public function holdRailpackImage(string $composePath): ?HeldImage
    {
        $fs = $this->project->system()->filesystem();
        $image = $fs->fileExists($composePath) ? $this->railpackImage($fs->fileGetContents($composePath)) : null;

        return $image === null ? null : HeldImage::hold($this->project, $image);
    }

    private function railpackImage(string $compose): ?string
    {
        return $this->project->userModel()->getDeployStrategy() === Strategies::RAILPACK
            ? GeneratedCompose::appImage($compose)
            : null;
    }

    /** Even when the account has the tag: a push target's may be from older code, and the start does not build. */
    private function buildRailpackImage(string $compose, string $projectDir): void
    {
        $image = $this->railpackImage($compose);
        if ($image === null) {
            return;
        }
        $built = $this->project->strategy()->railpack()->buildImage(
            $projectDir,
            $this->project->userModel()->getChownString(),
            $image
        );
        if (! $built) {
            throw new \RuntimeException(self::unavailable([$image]));
        }
    }

    private function preload(string $composePath): void
    {
        $model = $this->project->userModel();
        $inner = $this->project->innerDocker();
        $image = $model->getDeployImage();
        if (is_string($image) && $image !== '') {
            $inner->ensureImage($image);
        }
        if (! DeployCompose::skipBuild($model->getDeployStrategy(), $model->getDeployRuntime())) {
            $inner->preloadFrameworkBaseImages($model->getDeployStrategy(), $model->getDeployRuntime());
        }
        $inner->preloadComposeImages($composePath);
    }

    /**
     * Only our own images: a public one compose still pulls itself, while one
     * of ours exists on no registry it would ask.
     */
    private function refuseMissing(string $composePath): void
    {
        $missing = array_values(array_filter(
            $this->project->innerDocker()->missingComposeImages($composePath),
            BuiltImage::isOurs(...)
        ));
        if ($missing !== []) {
            throw new \RuntimeException(self::unavailable($missing));
        }
    }

    /**
     * @param  list<string>  $images
     */
    private static function unavailable(array $images): string
    {
        return 'The copied application needs '.implode(', ', $images).', which this project does not have '
            .'and could neither fetch nor build. Redeploy the project to build it.';
    }
}
