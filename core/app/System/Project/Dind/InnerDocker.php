<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\Lib\Deploy\Engine\ImageStore;

/**
 * The Docker daemon running *inside* an account's dind container.
 *
 * Two concerns only make sense for that nested daemon, and they are what the
 * collaborators below divide up: getting base images into it without a Hub
 * round-trip per account, and keeping its data-root — which lives on the
 * account's disk quota at ~/docker, not the host's — from filling up.
 *
 * Every command issued comes from the account's container engine
 * ({@see \App\Lib\Deploy\Engine\ContainerEngine}) rather than being written
 * out here, so the policy — when to seed, when to reclaim, when to fall back
 * to the stock image — holds for whichever engine is configured.
 *
 *   {@see ImageSeeding}      get images in, preferring the host over the registry
 *   {@see SharedBaseImages}  bases the host builds once and every account borrows
 *   {@see StorageReclaim}    the inner daemon's disk, which is the account's disk
 *   {@see HostCommands}      running host-side work, and saying so in the deploy log
 */
class InnerDocker
{
    private DindProject $project;

    private ?ImageSeeding $seeding = null;
    private ?SharedBaseImages $bases = null;
    private ?StorageReclaim $storage = null;
    private ?HostCommands $host = null;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    public function dind(): DindProject
    {
        return $this->project;
    }

    /** The engine's image operations for this account. */
    public function imageStore(): ImageStore
    {
        return $this->project->engine()->images();
    }

    // -------------------------------------------------------------------------
    // Collaborators
    // -------------------------------------------------------------------------

    public function seeding(): ImageSeeding
    {
        return $this->seeding ??= new ImageSeeding($this);
    }

    public function bases(): SharedBaseImages
    {
        return $this->bases ??= new SharedBaseImages($this);
    }

    public function storage(): StorageReclaim
    {
        return $this->storage ??= new StorageReclaim($this);
    }

    public function host(): HostCommands
    {
        return $this->host ??= new HostCommands($this);
    }

    // -------------------------------------------------------------------------
    // Base image availability — see {@see ImageSeeding}, {@see SharedBaseImages}
    // -------------------------------------------------------------------------

    public function preloadFrameworkBaseImages(?string $strategy = null, ?string $runtime = null): void
    {
        $this->seeding()->preloadFramework($strategy, $runtime);
    }

    /**
     * @param list<string> $images
     */
    public function preloadRailpackBaseImages(array $images): void
    {
        $this->seeding()->preloadRailpack($images);
    }

    public function preloadComposeImages(string $composePath): void
    {
        $this->seeding()->preloadCompose($composePath);
    }

    public function ensureImage(string $image): void
    {
        $this->seeding()->ensure($image);
    }

    /**
     * @return list<int>
     */
    public function declaredImagePorts(string $image): array
    {
        return $this->seeding()->declaredImagePorts($image);
    }

    /**
     * @param list<string> $extras
     * @param list<string> $packages
     * @return array{tag: ?string, baked: list<string>}
     */
    public function ensurePhpBaseImage(string $phpImage, array $extras = [], array $packages = []): array
    {
        return $this->bases()->ensurePhp($phpImage, $extras, $packages);
    }

    /**
     * @param list<string> $packages
     */
    public function ensureRubyBaseImage(string $rubyImage, array $packages): ?string
    {
        return $this->bases()->ensureRuby($rubyImage, $packages);
    }

    /**
     * @param list<string> $packages
     */
    public function ensurePythonBaseImage(string $pythonImage, array $packages): ?string
    {
        return $this->bases()->ensurePython($pythonImage, $packages);
    }

    // -------------------------------------------------------------------------
    // Build cache and data-root — see {@see StorageReclaim}
    // -------------------------------------------------------------------------

    public function reclaimStorageIfNeeded(): void
    {
        $this->storage()->reclaimIfNeeded();
    }

    public function canAggressivelyReclaim(): bool
    {
        return $this->storage()->canAggressivelyReclaim();
    }

    public function reclaimStorage(bool $emergency): void
    {
        $this->storage()->reclaim($emergency);
    }

    public function wipeDataRoot(): void
    {
        $this->storage()->wipeDataRoot();
    }
}
