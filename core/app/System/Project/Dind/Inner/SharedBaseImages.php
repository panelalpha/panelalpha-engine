<?php

namespace App\System\Project\Dind\Inner;

use App\System\Project\Dind\InnerDocker;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Telemetry\Telemetry;

/**
 * Base images the host builds once and every account borrows.
 *
 * A PHP image with the standard extension set already compiled, or a Ruby
 * image with the apt packages native gems need already installed. Building
 * one costs minutes; pulling it from the cache registry costs seconds, so the
 * whole design is about making sure nobody waits for the build.
 *
 * The degradation is deliberately one step at a time. A variant the host has
 * never built is queued for the background and *this* deploy falls back to
 * the plain shared base — which still saves it the standard set — rather than
 * dropping all the way to the stock image and compiling the lot. Only when
 * there is no shared base at all does it reach the stock image, and that case
 * is reported, because the deploy still goes green and nothing else would
 * record that the account paid ~30s it should not have.
 */
class SharedBaseImages
{
    private const BUILD_TIMEOUT_SECONDS = 1800;

    private const LOAD_TIMEOUT_SECONDS = 600;

    private InnerDocker $inner;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Make the shared PanelAlpha PHP base available to this account, building
     * it on the host the first time any account needs that PHP minor.
     *
     * Returns the tag once the inner daemon has it, or null when the caller
     * should fall back to the stock php image and compile the extensions in
     * the account — a slow deploy is better than a broken one.
     *
     * @param list<string> $extras extensions to bake in on top of the standard
     *                             set, so the account never compiles them
     * @param list<string> $packages the manifest's `system_packages:`
     * @return array{tag: ?string, baked: list<string>} baked is what $tag has
     *         beyond the standard set, so the caller knows what not to install
     */
    public function ensurePhp(string $phpImage, array $extras = [], array $packages = []): array
    {
        $extras = PhpBaseImage::normalizeExtras($extras);
        $packages = PhpBaseImage::normalizeSystemPackages($packages);
        if ($packages !== []) {
            return $this->ensurePhpWithPackages($phpImage, $extras, $packages);
        }
        if ($extras !== []) {
            $tag = PhpBaseImage::tag($phpImage, $extras);
            if ($tag !== null && $this->providePhp($tag, $phpImage, $extras)) {
                return ['tag' => $tag, 'baked' => $extras];
            }
        }

        $tag = PhpBaseImage::tag($phpImage);
        if ($tag !== null && $this->providePhp($tag, $phpImage)) {
            return ['tag' => $tag, 'baked' => []];
        }

        // All the way down to the stock image: this account is about to compile
        // the whole standard extension set itself, ~30s of build it should not
        // have paid for. The deploy still succeeds, so nothing else records it.
        Telemetry::signal(
            $this->inner->dind()->userModel()->username,
            'php-base-fallback',
            "No shared PHP base available for {$phpImage}; building from the stock image"
        );

        return ['tag' => null, 'baked' => []];
    }

    /**
     * A variant carrying system packages is never deferred and never swapped
     * for the plain base: the plain base has no ffmpeg, so a green deploy on it
     * accepts uploads it can never convert (ClipBucket, engine#193). The first
     * deploy of a set waits for the build; one that cannot get it fails.
     *
     * @param list<string> $extras
     * @param list<string> $packages
     * @return array{tag: string, baked: list<string>}
     */
    private function ensurePhpWithPackages(string $phpImage, array $extras, array $packages): array
    {
        $tag = PhpBaseImage::tag($phpImage, $extras, $packages);
        if ($tag !== null && $this->providePhp($tag, $phpImage, $extras, $packages)) {
            return ['tag' => $tag, 'baked' => $extras];
        }

        $wanted = implode(', ', $packages);
        throw new \RuntimeException(
            $tag === null
                ? "This application needs {$wanted} (system_packages), which only the shared PHP base image can carry, "
                    . "and {$phpImage} is not an image this host builds one from."
                : "This application needs {$wanted} (system_packages), and the PHP base image {$tag} "
                    . 'carrying them could not be built or loaded; see the lines above. '
                    . 'Deploying on the plain base would serve an application without them.'
        );
    }

    /**
     * The Ruby equivalent of {@see ensurePhp()}: a ruby image with the apt
     * packages native gems need already installed, so the account's build
     * starts at `bundle install`.
     *
     * Returns null when the caller should build FROM the stock ruby image and
     * install the packages itself.
     *
     * @param list<string> $packages
     */
    public function ensureRuby(string $rubyImage, array $packages): ?string
    {
        $tag = RubyBaseImage::tag($rubyImage, $packages);
        if ($tag === null || !ImageTransfer::isSafeImageRef($tag)) {
            return null;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return $tag;
        }

        $dockerfile = RubyBaseImage::dockerfile($rubyImage, $packages);
        // No stub in the catalogue, so there is no recipe to build from and
        // the account uses the stock image.
        if ($dockerfile === null) {
            return null;
        }

        if (!$this->hostHasImage($tag)) {
            // Same reasoning as the PHP base: the account waiting on this
            // deploy should not pay for an apt install the host has never
            // done. Queue it and let this build install its own packages.
            $this->queueBackgroundBuild('Ruby', $tag, $dockerfile);

            return null;
        }

        try {
            $this->buildAndLoad('Ruby', $tag, $dockerfile);
        } catch (\Exception $e) {
            $this->inner->host()->logInfo("Could not provide {$tag}: " . $e->getMessage());

            return null;
        }

        return $this->inner->seeding()->hasImage($tag) ? $tag : null;
    }

    /**
     * The Python equivalent of {@see ensureRuby()}: a python image with the
     * headers a source build needs already installed, so a dependency with no
     * wheel compiles instead of ending the deploy.
     *
     * Null when the caller should run the stock python image and take its
     * chances — a real outcome, not a failure: most projects install entirely
     * from wheels.
     *
     * @param list<string> $packages
     */
    public function ensurePython(string $pythonImage, array $packages): ?string
    {
        $tag = PythonBaseImage::tag($pythonImage, $packages);
        if ($tag === null || !ImageTransfer::isSafeImageRef($tag)) {
            return null;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return $tag;
        }

        $dockerfile = PythonBaseImage::dockerfile($pythonImage, $packages);
        // No stub in the catalogue, so there is no recipe to build from and
        // the account uses the stock image.
        if ($dockerfile === null) {
            return null;
        }

        if (!$this->hostHasImage($tag)) {
            // The catalogue decides whether a deploy waits. Ruby does not: its
            // per-project Dockerfile installs the same packages. Python does,
            // the base being the only place those headers exist — deferring it
            // meant a first deploy that failed on `pg_config executable not
            // found` and a second that worked.
            if (!RuntimeImageCatalog::runnable('python')) {
                $this->queueBackgroundBuild('Python', $tag, $dockerfile);

                return null;
            }

            $this->inner->host()->logInfo(
                "Shared Python base image {$tag} has not been built on this host yet; building it now. "
                . 'Later deploys on this Python version and dependency set load it in seconds. '
                . '`php artisan system:image:prewarm` builds it ahead of time.'
            );
        }

        try {
            $this->buildAndLoad('Python', $tag, $dockerfile);
        } catch (\Exception $e) {
            // Not fatal: the stock image installs everything that ships a
            // wheel, and the rest get a clear pip error rather than a deploy
            // refused up front.
            $host = $this->inner->host();
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo('Shared Python base unavailable, using the stock image: ' . $e->getMessage());

            return null;
        }

        return $this->inner->seeding()->hasImage($tag) ? $tag : null;
    }

    /**
     * $image with Node copied in, for a host build that runs a JS tool
     * ({@see NodeBuildImage}). Built on the host only, since only a host
     * build uses it; nothing is loaded into the account.
     *
     * Built now rather than in the background: without Node the build fails
     * every time, and the image is COPY layers, seconds once both sources
     * are on the host. Null means build in $image and let it fail as before.
     */
    public function ensureNodeBuild(string $image, string $nodeImage): ?string
    {
        $tag = NodeBuildImage::tag($image, $nodeImage);
        $dockerfile = NodeBuildImage::dockerfile($image, $nodeImage);
        if ($tag === null || $dockerfile === null) {
            return null;
        }
        $host = $this->inner->host();
        $host->logInfo("The build runs a JS tool; building in {$tag} ({$image} with Node from {$nodeImage})");
        if ($this->hostHasImage($tag)) {
            return $tag;
        }

        try {
            $host->cancellable($this->inner->imageStore()->hostBuildCommand($tag, $dockerfile), self::BUILD_TIMEOUT_SECONDS);
        } catch (\Exception $e) {
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo("Could not build {$tag}, building without Node: " . $e->getMessage());

            return null;
        }

        return $tag;
    }

    /**
     * Build on the host, then load into the account. Both halves no-op when the
     * image is already where it needs to be, so this is safe to call per deploy.
     *
     * @param list<string> $extras
     * @param list<string> $packages
     */
    public function providePhp(string $tag, string $phpImage, array $extras = [], array $packages = []): bool
    {
        if (!ImageTransfer::isSafeImageRef($tag)) {
            return false;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return true;
        }

        $dockerfile = PhpBaseImage::dockerfile($phpImage, $extras, $packages);
        // No stub in the catalogue, so there is no recipe to build from and
        // the account uses the stock image.
        if ($dockerfile === null) {
            return false;
        }

        // A variant the host has never built is worth deferring: the plain
        // base is a complete image and this deploy runs on it while the
        // variant compiles for whoever asks next.
        //
        // The plain base is not deferrable. There is no per-project Dockerfile
        // to fall back to any more -- it *is* the runtime, Apache
        // configuration and entrypoint included -- so a deploy without it has
        // nothing to run. Better a first deploy on this minor that waits for
        // the compile and says so than a green deploy serving nothing.
        // System packages are not deferrable either: the plain base lacks them.
        if (!$this->hostHasImage($tag) && $extras !== [] && $packages === []) {
            $this->queueBackgroundBuild('PHP', $tag, $dockerfile);

            return false;
        }
        if (!$this->hostHasImage($tag)) {
            $this->inner->host()->logInfo(
                "Shared PHP base image {$tag} has not been built on this host yet; building it now"
                . ($packages === [] ? '' : ' with ' . implode(', ', $packages)) . '. '
                . 'Later deploys on this PHP version load it in seconds. '
                . '`php artisan system:image:prewarm` builds it ahead of time.'
            );
        }

        try {
            $this->buildAndLoad('PHP', $tag, $dockerfile);
        } catch (\Exception $e) {
            $host = $this->inner->host();
            $host->failDeployIfDiskFull($e->getMessage());
            // The stock-image fallback below cannot serve a PHP app any more, and the
            // registry is the only way in: fail with the cause, not a later side effect.
            if (str_contains($e->getMessage(), DindImageStore::REGISTRY_DOWN)) {
                throw new \RuntimeException(
                    'The image cache (panelalpha-cache-registry) is not running, and a PHP deploy '
                    . 'cannot get its base image without it. Start it with `docker compose up -d cache-registry`.'
                );
            }
            $host->logInfo(
                'Shared PHP base image unavailable, compiling extensions in the account: ' . $e->getMessage()
            );

            return false;
        }

        return $this->inner->seeding()->hasImage($tag);
    }

    /**
     * Hand the build to the background and let this deploy use the stock
     * image. Best-effort by design: if it fails, the only cost is that the
     * next deploy tries again.
     */
    private function queueBackgroundBuild(string $language, string $tag, string $dockerfile): void
    {
        $host = $this->inner->host();
        $host->logInfo(
            "Building shared {$language} base image {$tag} in the background; this deploy uses the stock image"
        );
        $host->inBackground($this->inner->imageStore()->hostBuildCommand($tag, $dockerfile));
    }

    /**
     * Build on the host, then push it through the cache registry into the
     * account. Only reached once neither the registry nor the host had it.
     *
     * Throws rather than reporting, because the two callers answer a failure
     * differently: PHP has a stock image to fall back to and a disk-full to
     * re-raise, Ruby only has the packages to install itself.
     */
    private function buildAndLoad(string $language, string $tag, string $dockerfile): void
    {
        $host = $this->inner->host();
        $store = $this->inner->imageStore();

        $host->logInfo("Preparing shared {$language} base image {$tag}");
        $host->cancellable($store->hostBuildCommand($tag, $dockerfile), self::BUILD_TIMEOUT_SECONDS);
        $host->cancellable(
            $store->loadFromHostCommand($this->inner->dind()->engineAccount(), $tag),
            self::LOAD_TIMEOUT_SECONDS
        );
    }

    /** Does the *host* already have this image, i.e. is providing it a push rather than a compile? */
    private function hostHasImage(string $image): bool
    {
        if (!ImageTransfer::isSafeImageRef($image)) {
            return false;
        }
        try {
            $this->inner->dind()->system()->execOnHost(
                $this->inner->imageStore()->hostImageInspectArgv($image)
            );

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
