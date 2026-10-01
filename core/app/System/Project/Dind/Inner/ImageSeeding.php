<?php

namespace App\System\Project\Dind\Inner;

use App\System\Project\Dind\InnerDocker;
use App\Lib\Deploy\Dind\RegistryConfigSync;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\RailpackCache;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\Runtime\Images;

/**
 * Getting images into the inner daemon before a build or `compose up` asks.
 *
 * Every route is a registry pull; {@see DindImageStore::seedCommand()} has the
 * order. Nothing here is required for correctness: `compose up` pulls whatever
 * is still missing.
 */
class ImageSeeding
{
    private const PARALLEL_SEED_TIMEOUT_SECONDS = 900;

    private const SEED_TIMEOUT_SECONDS = 600;

    private InnerDocker $inner;

    private bool $registryConfigChecked = false;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /** Ensure recipe base images are in the inner daemon. */
    public function preloadFramework(?string $strategy = null, ?string $runtime = null): void
    {
        $images = Images::preloadImagesFor(
            $strategy,
            $runtime,
            $this->inner->dind()->userAppDirPath()
        );
        foreach ($images as $image) {
            if (!is_string($image) || $image === '' || !ImageTransfer::isSafeImageRef($image)) {
                continue;
            }
            $this->ensure($image);
        }
    }

    /**
     * Get the images a Railpack build needs into the account before
     * `docker buildx build` runs there, so the nested daemon does not pull
     * them through the account's NAT.
     *
     * Told what they are rather than deciding: {@see RailpackCache::preloadImages()}
     * reads them out of the plan railpack just wrote, which is the only thing
     * that knows which builder and runtime tags it resolved to.
     *
     * @param list<string> $images
     */
    public function preloadRailpack(array $images): void
    {
        foreach ($images as $image) {
            $this->ensure($image);
        }
    }

    /** Sidecars (mysql, redis, …), before `compose up` pulls them one at a time. */
    public function preloadCompose(string $composePath): void
    {
        $images = $this->composeImages($composePath);
        if ($images === []) {
            return;
        }

        // Several at once finish far sooner than one after another, but only
        // where the host can take it: on a 2-core VPS this resolves to 1.
        $concurrency = $this->seedConcurrency(count($images));
        if ($concurrency <= 1) {
            foreach ($images as $image) {
                $this->ensure($image);
            }

            return;
        }

        $this->seedInParallel($images, $concurrency);

        // Whatever the parallel pass missed still has to be there. Each check
        // is a round-trip into the account, so ask the inner daemon once for
        // everything it holds rather than once per image.
        $present = $this->presentImages();
        foreach ($images as $image) {
            if (!in_array($image, $present, true)) {
                $this->ensure($image);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function composeImages(string $composePath): array
    {
        $system = $this->inner->dind()->system();
        $fs = $system->filesystem();
        if (!$fs->fileExists($composePath)) {
            return [];
        }
        $contents = $fs->fileGetContents($composePath);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $images = [];
        $profiles = $this->activeProfiles(dirname($composePath) . '/.env');
        foreach (DeployCompose::imageRefs($contents, activeProfiles: $profiles) as $image) {
            if (is_string($image) && $image !== '' && ImageTransfer::isSafeImageRef($image)) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * COMPOSE_PROFILES as `compose up` will read it from the project's .env;
     * none set means only unprofiled services start.
     *
     * @return list<string>
     */
    private function activeProfiles(string $envPath): array
    {
        $fs = $this->inner->dind()->system()->filesystem();
        $contents = $fs->fileExists($envPath) ? $fs->fileGetContents($envPath) : '';
        foreach (EnvFile::parse(is_string($contents) ? $contents : '') as $row) {
            if (($row['type'] ?? '') === 'variable' && ($row['key'] ?? '') === 'COMPOSE_PROFILES') {
                return array_values(array_filter(array_map('trim', explode(',', (string) ($row['value'] ?? '')))));
            }
        }

        return [];
    }

    /**
     * @param list<string> $images
     */
    private function seedInParallel(array $images, int $concurrency): void
    {
        $this->ensureRegistryConfig();
        $host = $this->inner->host();
        $host->logDim("Seeding {$concurrency} base images at a time");
        try {
            // bash, not sh: the script throttles with `jobs -rp` and `wait -n`,
            // which dash does not have. Under dash the cap would silently stop
            // applying and every image would be seeded at once.
            $host->cancellable(
                [
                    'bash',
                    '-c',
                    $this->inner->imageStore()->parallelImportCommand(
                        $this->inner->dind()->engineAccount(),
                        $images,
                        $concurrency
                    ),
                ],
                self::PARALLEL_SEED_TIMEOUT_SECONDS
            );
        } catch (\Exception $e) {
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo('Parallel image seed failed, falling back: ' . $e->getMessage());
        }
    }

    /**
     * Every image tag the inner daemon already has, in one call.
     *
     * @return list<string>
     */
    private function presentImages(): array
    {
        try {
            $output = $this->inner->dind()->shell()->execQuiet(
                $this->inner->imageStore()->listImagesArgv(),
                [],
                60
            );
        } catch (\Exception $e) {
            return [];
        }

        $tags = preg_split('/\r?\n/', trim((string) $output)) ?: [];

        return array_values(array_filter($tags, static fn (string $t): bool => $t !== '' && $t !== '<none>:<none>'));
    }

    /**
     * How many seeds this host can run at once right now. Operator setting
     * wins; otherwise derived from cores, load and free memory so the same
     * code is safe on a minimum VPS and fast on a large one.
     */
    private function seedConcurrency(int $imageCount): int
    {
        $override = config('env.DIND_SEED_CONCURRENCY');
        $override = is_numeric($override) ? (int) $override : null;

        return ImageTransfer::concurrencyFor(
            ImageTransfer::parseCpuCount((string) @file_get_contents('/proc/cpuinfo')),
            ImageTransfer::parseLoadAvg((string) @file_get_contents('/proc/loadavg')),
            ImageTransfer::parseMemAvailableMb((string) @file_get_contents('/proc/meminfo')),
            $imageCount,
            $override
        );
    }

    /**
     * Ports an image declares for itself, straight from its own metadata.
     *
     * This is what lets an unfamiliar vendor image be recognised for what it
     * is: we may never have heard of myorg/our-postgres, but it still says
     * 5432. From the host store when it holds the image, otherwise from the
     * registries. Best-effort: nothing found means weaker evidence, not an error.
     *
     * @return list<int>
     */
    public function declaredImagePorts(string $image): array
    {
        if ($image === '' || !ImageTransfer::isSafeImageRef($image)) {
            return [];
        }

        try {
            $output = $this->inner->dind()->system()->exec(
                $this->inner->imageStore()->hostImageExposedPortsArgv($image),
                [],
                30
            );
        } catch (\Exception $e) {
            return (new RegistryImageConfig())->exposedPorts($image);
        }

        return ImageTransfer::parseExposedPorts(is_string($output) ? $output : '');
    }

    public function ensure(string $image): void
    {
        if ($this->hasImage($image)) {
            return;
        }

        // Ours, and never on a public registry: the cache registry, the host,
        // or a rebuild. Which images those are comes from the catalogue.
        if (BuiltImage::isOurs($image)) {
            if (!$this->provideBuiltImage($image)) {
                $this->inner->host()->logInfo(
                    "Base image {$image} is one of ours and is not on this host; continuing without it"
                );
            }

            return;
        }

        $this->seed($image, false);
    }

    /**
     * From the cache registry, or from the host through it. What a shared base
     * tries before anything is built.
     */
    public function provideFromStores(string $image): bool
    {
        return $this->seed($image, true);
    }

    /**
     * Get one of our own images into the account: from the registry or the
     * host if either has it, by rebuilding it if we can, otherwise not at all.
     *
     * A variant tag is the "not at all" case. Its `-x…` suffix hashes the set
     * baked in on top, and a hash does not go backwards, so rebuilding from the
     * tag alone yields the plain base wearing a name that promises imagick.
     * {@see SharedBaseImages::ensurePhp()} still holds the set and rebuilds it
     * properly; here it is loaded or done without.
     */
    private function provideBuiltImage(string $image): bool
    {
        if ($this->seed($image, true)) {
            return true;
        }

        if (!BuiltImage::isRebuildableFromTag($image)) {
            return false;
        }

        $source = BuiltImage::sourceImage($image);
        if ($source === null) {
            return false;
        }

        return match (BuiltImage::runtimeFor($image)) {
            'php' => $this->inner->bases()->providePhp($image, $source),
            // Ruby's Dockerfile is its apt package list, which lives in the
            // same one-way fingerprint as PHP's extras: loaded, never rebuilt.
            default => false,
        };
    }

    /** Run the seed ladder for one image and log the line saying where it came from. */
    private function seed(string $image, bool $ours): bool
    {
        $this->ensureRegistryConfig();
        $host = $this->inner->host();
        // Opens the image_transfer span; the ladder's own line closes it.
        $host->logInfo("Fetching base image {$image}");
        try {
            $output = $this->inner->dind()->system()->exec(
                $this->inner->imageStore()->seedCommand(
                    $this->inner->dind()->engineAccount(),
                    $image,
                    $ours
                ),
                [],
                self::SEED_TIMEOUT_SECONDS
            );
        } catch (\Exception $e) {
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo("Could not get {$image} into the account: " . trim($e->getMessage()));

            return false;
        }

        $line = trim((string) $output);
        if ($line !== '') {
            $host->logInfo($line);
        }

        return $this->hasImage($image);
    }

    /**
     * Once per deploy, on the first image question.
     *
     * An account created after the mount shipped reads that file directly:
     * {@see \App\System\Project\Dind::rewriteDaemonJsonInPlace()} keeps the
     * same inode the bind mount already serves (refusing a symlink there,
     * engine#524), so HUPing the account's own dockerd from the host (never a
     * shell inside the account) is enough to pick it up, no restart needed.
     *
     * An account that predates the mount has no mount to tell apart from one
     * that is simply missing, so it only gets the plain, symlink-safe render
     * {@see \App\System\Project\Dind::setupDaemonJson()} writes anyway -- its
     * own init script still writes a correct daemon.json on its own next
     * boot, and it gets the mount itself the next time it is recreated.
     */
    private function ensureRegistryConfig(): void
    {
        if ($this->registryConfigChecked) {
            return;
        }
        $this->registryConfigChecked = true;

        $dind = $this->inner->dind();
        $fs = $dind->system()->filesystem();
        $path = $dind->daemonJsonPath();
        $rendered = $dind->daemonJsonContents();

        try {
            $current = $fs->fileExists($path) ? $fs->fileGetContents($path) : null;
            if ($current === $rendered) {
                return;
            }

            if (!$this->accountHasDaemonJsonMount()) {
                // No bind mount to keep live: a plain, symlink-safe render,
                // which is what this account gets when it is next recreated
                // either way. Nothing to signal -- its own init script is
                // what applies this on its own next boot.
                $dind->setupDaemonJson();

                return;
            }

            // Mounted: write the existing inode in place so the running
            // container's view changes too, then HUP its dockerd to read it.
            if (!$dind->rewriteDaemonJsonInPlace()) {
                return;
            }
            $pid = $this->dockerdHostPid();
            if ($pid === null) {
                return;
            }
            $dind->system()->execOnHost($this->inner->imageStore()->hostSignalDockerdArgv($pid));
            $this->inner->host()->logInfo('Pointed this account\'s Docker at the engine\'s image registries');
        } catch (\Exception $e) {
            // Not fatal: public images still come straight from their registries.
            $this->inner->host()->logDim('Could not update this account\'s registry settings: ' . trim($e->getMessage()));
        }
    }

    /** Whether the running container already binds daemon.json from the host, or still carries its own. */
    private function accountHasDaemonJsonMount(): bool
    {
        try {
            $json = $this->inner->dind()->system()->exec(
                $this->inner->imageStore()->hostAccountMountsArgv($this->inner->dind()->engineAccount()),
                [],
                15
            );
        } catch (\Exception $e) {
            return false;
        }

        return RegistryConfigSync::hasDaemonJsonMount((string) $json);
    }

    /** The account's dockerd, by the PID the host can actually signal -- the account is its own PID namespace. */
    private function dockerdHostPid(): ?int
    {
        try {
            $output = $this->inner->dind()->system()->exec(
                $this->inner->imageStore()->hostAccountProcessesArgv($this->inner->dind()->engineAccount()),
                [],
                15
            );
        } catch (\Exception $e) {
            return null;
        }

        return RegistryConfigSync::dockerdHostPid((string) $output);
    }

    public function hasImage(string $image): bool
    {
        // Every deploy asks this first, even when each image is already there.
        $this->ensureRegistryConfig();
        try {
            $id = trim($this->inner->dind()->shell()->execQuiet(
                $this->inner->imageStore()->imageIdArgv($image),
                [],
                30
            ));

            return $id !== '';
        } catch (\Exception $e) {
            return false;
        }
    }
}
