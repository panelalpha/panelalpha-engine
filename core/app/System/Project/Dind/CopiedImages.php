<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;

/**
 * The images a run file copied from another project names, got into this
 * account before `compose create` or `up` asks for them.
 *
 * Every account has an image store of its own, so a staging, clone or push
 * target starts without the source's. Our shared bases and public images
 * arrive the way a deploy fetches them ({@see AppLauncher}); a service with a
 * `build:` is built from the copied tree by compose itself. That leaves an
 * image the source's deploy built outside compose -- Railpack's -- which is
 * built again here from the copied tree, because a tenant's image never goes
 * through the shared cache registry. It is built even when the account
 * already has that tag: a push target keeps the one an earlier push built
 * from older code, and the start does not build. A push holds that one
 * aside first ({@see HeldImage}), so a failed push can put it back. One of
 * those, or one of our own bases, that still cannot be had fails with its
 * name, not with a registry's "not found".
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
