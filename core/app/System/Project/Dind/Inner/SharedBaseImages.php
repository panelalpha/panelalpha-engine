<?php

namespace App\System\Project\Dind\Inner;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\CacheManager\RustBuildToolsImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Project\Dind\InnerDocker;

/**
 * Base images the host builds once and every account pulls in seconds. A missing variant falls back one step,
 * to the plain shared base, while it builds in the background; only no base at all reaches the stock image.
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
     * The shared PHP base for this account, built on the host the first time any account needs the minor;
     * no tag means the stock php image, compiling the extensions in the account.
     *
     * @param  list<string>  $extras  extensions baked in on top of the standard set
     * @param  list<string>  $packages  the manifest's `system_packages:`
     * @param  list<string>  $required  extensions composer requires: a variant carrying one is not deferred
     * @return array{tag: ?string, baked: list<string>} baked is what $tag has beyond the standard set
     */
    public function ensurePhp(string $phpImage, array $extras = [], array $packages = [], array $required = []): array
    {
        $extras = PhpBaseImage::normalizeExtras($extras);
        $packages = PhpBaseImage::normalizeSystemPackages($packages);
        if ($packages !== []) {
            return $this->ensurePhpWithPackages($phpImage, $extras, $packages);
        }
        if ($extras !== []) {
            $variant = $this->phpVariant($phpImage, $extras, $required);
            if ($variant !== null) {
                return $variant;
            }
        }

        $tag = PhpBaseImage::tag($phpImage);
        if ($tag !== null && $this->providePhp($tag, $phpImage)) {
            return ['tag' => $tag, 'baked' => []];
        }
        $this->failWithoutPlainPhpBase($tag, $phpImage);
        $this->reportStockPhpFallback($phpImage);

        return ['tag' => null, 'baked' => []];
    }

    /**
     * @param  list<string>  $extras
     * @param  list<string>  $required
     * @return ?array{tag: string, baked: list<string>}
     */
    private function phpVariant(string $phpImage, array $extras, array $required): ?array
    {
        $tag = PhpBaseImage::tag($phpImage, $extras);
        $needed = array_values(array_intersect($extras, array_map('strtolower', $required)));
        if ($tag !== null && $this->providePhp($tag, $phpImage, $extras, [], $needed)) {
            return ['tag' => $tag, 'baked' => $extras];
        }

        return null;
    }

    /**
     * The base is the runtime: the stock image has no Composer, Apache configuration or entrypoint, so
     * falling back to it would fail later as `composer: not found`.
     */
    private function failWithoutPlainPhpBase(?string $tag, string $phpImage): void
    {
        if ($tag !== null && RuntimeImageCatalog::runnable('php')) {
            throw new \RuntimeException(
                "The shared PHP base image {$tag} could not be built or loaded on this server; see the lines above. "
                .'A PHP app runs on that image (Composer, the Apache configuration and the entrypoint are baked '
                ."into it), and the stock {$phpImage} has none of them. Deploy again once the cause above is "
                .'fixed; `php artisan system:image:prewarm` builds the base ahead of a deploy.'
            );
        }
    }

    /**
     * The account is about to compile the whole standard set itself, ~30s it should not pay; the deploy
     * still succeeds, so only this records it.
     */
    private function reportStockPhpFallback(string $phpImage): void
    {
        Telemetry::signal(
            $this->inner->dind()->userModel()->username,
            'php-base-fallback',
            "No shared PHP base available for {$phpImage}; building from the stock image"
        );
    }

    /**
     * A variant carrying system packages is never deferred or swapped for the plain base, which has no ffmpeg
     * (ClipBucket): the first deploy of a set waits for the build, and one that cannot get it fails.
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
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
                    ."and {$phpImage} is not an image this host builds one from."
                : "This application needs {$wanted} (system_packages), and the PHP base image {$tag} "
                    .'carrying them could not be built or loaded; see the lines above. '
                    .'Deploying on the plain base would serve an application without them.'
        );
    }

    /**
     * A ruby image with native gems' apt packages installed, or null to build FROM the stock image: without a
     * catalogue stub, or while a host that never built it builds it in the background, as the PHP base does.
     *
     * @param  list<string>  $packages
     */
    public function ensureRuby(string $rubyImage, array $packages): ?string
    {
        $tag = RubyBaseImage::tag($rubyImage, $packages);
        if ($tag === null || ! ImageTransfer::isSafeImageRef($tag)) {
            return null;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return $tag;
        }

        $dockerfile = RubyBaseImage::dockerfile($rubyImage, $packages);
        if ($dockerfile === null) {
            return null;
        }

        if (! $this->hostHasImage($tag)) {
            $this->queueBackgroundBuild('Ruby', $tag, $dockerfile);

            return null;
        }

        try {
            $this->buildAndLoad('Ruby', $tag, $dockerfile);
        } catch (\Exception $e) {
            $this->inner->host()->logInfo("Could not provide {$tag}: ".$e->getMessage());

            return null;
        }

        return $this->inner->seeding()->hasImage($tag) ? $tag : null;
    }

    /**
     * A python image with the headers a source build needs, so a dependency without a wheel compiles; null
     * runs the stock image, a real outcome since most projects install from wheels alone.
     *
     * @param  list<string>  $packages
     */
    public function ensurePython(string $pythonImage, array $packages): ?string
    {
        $tag = PythonBaseImage::tag($pythonImage, $packages);
        if ($tag === null || ! ImageTransfer::isSafeImageRef($tag)) {
            return null;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return $tag;
        }

        $dockerfile = PythonBaseImage::dockerfile($pythonImage, $packages);
        if ($dockerfile === null) {
            return null;
        }

        $announced = ! $this->hostHasImage($tag);
        if ($announced && ! $this->waitsForPythonBuild($tag, $dockerfile)) {
            return null;
        }

        return $this->loadPythonBase($tag, $dockerfile, $announced);
    }

    /**
     * The catalogue decides whether a deploy waits; Python does, the base being the only place the headers
     * exist: deferred, a first deploy failed on `pg_config executable not found`.
     */
    private function waitsForPythonBuild(string $tag, string $dockerfile): bool
    {
        if (! RuntimeImageCatalog::runnable('python')) {
            $this->queueBackgroundBuild('Python', $tag, $dockerfile);

            return false;
        }

        $this->inner->host()->logInfo(
            "Shared Python base image {$tag} "
            .(PublishedBaseImage::ref($tag) === null
                ? 'has not been built on this host yet; building it now. '
                : 'is not on this host yet; pulling the published copy, or building it now. ')
            .'Later deploys on this Python version and dependency set load it in seconds. '
            .'Prewarming can keep it built ahead of time.'
        );

        return true;
    }

    /**
     * Not fatal when it fails: the stock image installs whatever ships a wheel, and the rest get a clear pip
     * error rather than a deploy refused up front.
     */
    private function loadPythonBase(string $tag, string $dockerfile, bool $announced): ?string
    {
        try {
            $this->buildAndLoad('Python', $tag, $dockerfile, $announced);
        } catch (\Exception $e) {
            $host = $this->inner->host();
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo('Shared Python base unavailable, using the stock image: '.$e->getMessage());

            return null;
        }

        return $this->inner->seeding()->hasImage($tag) ? $tag : null;
    }

    /**
     * Pull one of ours back from the cache registry, or rebuild it from the extensions that named it; false
     * when the host still lacks it, as a tag hashing a set nobody here holds cannot be rebuilt.
     *
     * @param  list<string>  $extensions  the extras the base image was named with
     * @param  list<string>  $packages  the system packages it was named with
     */
    public function ensureOnHost(string $image, array $extensions, array $packages): bool
    {
        $argv = $this->inner->imageStore()->hostEnsureImageArgv($image);
        if ($argv === ['true']) {
            return true;
        }

        try {
            $this->inner->dind()->system()->exec($argv, [], 900);
        } catch (\Throwable $e) {
            $this->inner->host()->logInfo("Could not pull base image {$image} back onto the host: ".$e->getMessage());
        }
        if ($this->hostHasImage($image)) {
            return true;
        }
        $this->rebuildOnHost($image, $extensions, $packages);

        return $this->hostHasImage($image);
    }

    /**
     * $image with Node copied in ({@see NodeBuildImage}), built on demand on the host only, never pulled when it
     * is ours (Docker Hub has none); null means build in $image and let it fail there.
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

        $pull = ! $this->hostHasImage($image);
        try {
            $host->cancellable(
                $this->inner->imageStore()->hostBuildArgv($tag, $dockerfile, $pull),
                self::BUILD_TIMEOUT_SECONDS
            );
        } catch (\Exception $e) {
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo("Could not build {$tag}, building without Node: ".$e->getMessage());

            return null;
        }

        return $tag;
    }

    /**
     * $image with the apt packages a Rust build script needs ({@see RustBuildToolsImage}), built on demand on
     * the host only; null means compile in $image itself.
     *
     * @param  list<string>  $packages
     */
    public function ensureRustBuild(string $image, array $packages): ?string
    {
        $tag = RustBuildToolsImage::tag($image, $packages);
        $dockerfile = RustBuildToolsImage::dockerfile($image, $packages);
        if ($tag === null || $dockerfile === null) {
            return null;
        }
        $host = $this->inner->host();
        $host->logInfo('The Rust build needs '.implode(', ', $packages)."; building in {$tag}");
        if ($this->hostHasImage($tag)) {
            return $tag;
        }

        try {
            $host->cancellable(
                $this->inner->imageStore()->hostBuildArgv($tag, $dockerfile, ! $this->hostHasImage($image)),
                self::BUILD_TIMEOUT_SECONDS
            );
        } catch (\Exception $e) {
            $host->failDeployIfDiskFull($e->getMessage());
            $host->logInfo("Could not build {$tag}, compiling in {$image}: ".$e->getMessage());

            return null;
        }

        return $tag;
    }

    /**
     * Build on the host, then load into the account; both halves no-op when the image is already there, so
     * this is safe per deploy. Without a catalogue stub there is nothing to build, and the stock image runs.
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
     * @param  list<string>  $required  the extras composer will refuse to install without
     */
    public function providePhp(
        string $tag,
        string $phpImage,
        array $extras = [],
        array $packages = [],
        array $required = []
    ): bool {
        if (! ImageTransfer::isSafeImageRef($tag)) {
            return false;
        }
        if ($this->inner->seeding()->hasImage($tag) || $this->inner->seeding()->provideFromStores($tag)) {
            return true;
        }

        $dockerfile = PhpBaseImage::dockerfile($phpImage, $extras, $packages);
        if ($dockerfile === null) {
            return false;
        }

        if ($this->defersPhpVariant($tag, $extras, $packages, $required)) {
            $this->queueBackgroundBuild('PHP', $tag, $dockerfile);

            return false;
        }
        $announced = ! $this->hostHasImage($tag);
        if ($announced) {
            $this->announcePhpBuild($tag, $packages, $required);
        }

        return $this->loadPhpBase($tag, $dockerfile, $announced);
    }

    /**
     * Only an extension variant the host never built is deferred, this deploy running on the plain base; not
     * the plain base (the runtime), system packages, or an extension composer requires (Wallabag's ext-tidy).
     *
     * @param  list<string>  $extras
     * @param  list<string>  $packages
     * @param  list<string>  $required
     */
    private function defersPhpVariant(string $tag, array $extras, array $packages, array $required): bool
    {
        return ! $this->hostHasImage($tag) && $extras !== [] && $packages === [] && $required === [];
    }

    /**
     * @param  list<string>  $packages
     * @param  list<string>  $required
     */
    private function announcePhpBuild(string $tag, array $packages, array $required): void
    {
        $this->inner->host()->logInfo(
            "Shared PHP base image {$tag} "
            .(PublishedBaseImage::ref($tag) === null
                ? 'has not been built on this host yet; building it now'
                : 'is not on this host yet; pulling the published copy, or building it now')
            .($packages === [] ? '' : ' with '.implode(', ', $packages))
            .($required === [] ? '' : ', since this project requires '.implode(', ', $required)).'. '
            .'Later deploys on this PHP version load it in seconds. '
            .'Prewarming can keep it built ahead of time.'
        );
    }

    private function loadPhpBase(string $tag, string $dockerfile, bool $announced): bool
    {
        try {
            $this->buildAndLoad('PHP', $tag, $dockerfile, $announced);
        } catch (\Exception $e) {
            $host = $this->inner->host();
            $host->failDeployIfDiskFull($e->getMessage());
            self::failIfRegistryDown($e);
            $host->logInfo(
                'Shared PHP base image unavailable, compiling extensions in the account: '.$e->getMessage()
            );

            return false;
        }

        return $this->inner->seeding()->hasImage($tag);
    }

    /**
     * The registry is a PHP base's only way into an account: fail with the cause, not a later side effect.
     */
    private static function failIfRegistryDown(\Exception $e): void
    {
        if (str_contains($e->getMessage(), DindImageStore::REGISTRY_DOWN)) {
            throw new \RuntimeException(
                'The image cache (panelalpha-cache-registry or its writer) is not running, and a PHP deploy '
                .'cannot get its base image without it. Start it with `docker compose up -d cache-registry cache-registry-writer`.'
            );
        }
    }

    /**
     * Hand the build to the background and let this deploy use the stock image; a failure costs only a retry
     * by the next deploy.
     */
    private function queueBackgroundBuild(string $language, string $tag, string $dockerfile): void
    {
        $host = $this->inner->host();
        $host->logInfo(
            "Building shared {$language} base image {$tag} in the background; this deploy uses the stock image"
        );
        $host->inBackground($this->inner->imageStore()->hostBuildInBackgroundArgv($tag, $dockerfile));
    }

    /**
     * Build on the host and push it through the cache registry into the account, throwing for the caller to
     * answer; the `Preparing` line stays the image_transfer marker, dim once the caller said it is building.
     */
    private function buildAndLoad(string $language, string $tag, string $dockerfile, bool $announced = false): void
    {
        $host = $this->inner->host();
        $store = $this->inner->imageStore();

        $preparing = "Preparing shared {$language} base image {$tag}";
        $announced ? $host->logDim($preparing) : $host->logInfo($preparing);
        $host->cancellable($store->hostBuildArgv($tag, $dockerfile), self::BUILD_TIMEOUT_SECONDS);
        $host->cancellable(
            $store->loadFromHostArgv($this->inner->dind()->engineAccount(), $tag),
            self::LOAD_TIMEOUT_SECONDS
        );
    }

    /**
     * Rebuild a missing base image from the Dockerfile prewarm uses and publish it, so this deploy and the
     * accounts after it find it by name.
     *
     * @param  list<string>  $extensions
     * @param  list<string>  $packages
     */
    private function rebuildOnHost(string $image, array $extensions, array $packages): void
    {
        $dockerfile = BuiltImage::dockerfile($image, $extensions, $packages);
        if ($dockerfile === null) {
            return;
        }

        $this->inner->host()->logInfo("Base image {$image} was not on this server; building it now");
        try {
            $this->inner->host()->cancellable(
                $this->inner->imageStore()->hostRebuildArgv($image, $dockerfile),
                self::BUILD_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            $this->failUnlessOnlyThePushFailed($image, $e);
        }
    }

    /**
     * A build that worked and a push that did not leave the image on the host, all the host build runs: warn
     * and go on. Anything else is a failed rebuild.
     */
    private function failUnlessOnlyThePushFailed(string $image, \Throwable $e): void
    {
        if ($e instanceof DeployCancelledException || ! $this->hostHasImage($image)) {
            throw new \RuntimeException(
                "The base image {$image} is not on this server; rebuilding it failed. ".$e->getMessage()
            );
        }

        $this->inner->host()->logWarn(
            "Base image {$image} was rebuilt, but publishing it to the cache registry failed; this deploy goes on "
            .'with the server\'s copy. '.$e->getMessage()
        );
    }

    /** Does the *host* already have this image, i.e. is providing it a push rather than a compile? */
    private function hostHasImage(string $image): bool
    {
        if (! ImageTransfer::isSafeImageRef($image)) {
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
