<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
use App\Lib\Deploy\Template\Template;
use App\System\Privileged;
use App\System\Privileged\RootAccountDockerLines;
use App\System\Privileged\Scripts\ImagePipelines;
use App\System\Project\Dind;

/**
 * Gets images into an account's nested Docker daemon, through registries only.
 *
 * Baking base images into the account template does not work: the nested
 * daemon's data-root is `~/docker`, not the outer image's `/var/lib/docker`.
 * An account pulls from panelalpha-cache-registry (what the host built or
 * holds, pushed there first) or, for anything else, from the image's own
 * registry, Docker Hub going through panelalpha-registry-proxy. Never
 * `docker save | docker load`: it copies incomplete containerd-store images
 * without complaint.
 *
 * Reference validation and the seeding heuristics live in {@see ImageTransfer};
 * this class is only the Docker spelling of them. Builds commands only.
 */
final class DindImageStore implements ImageStore
{
    /**
     * The local registry as an **account** addresses it: a read-only instance,
     * since every account can reach it and pulls from it by tag.
     *
     * Every account's daemon is created with this in `insecure-registries`
     * ({@see Dind}), and the name resolves on
     * the engine network, so a pull needs no further setup.
     */
    public const CACHE_REGISTRY = 'panelalpha-cache-registry:5000';

    /**
     * The same registry as the **host** addresses it, which is not the same
     * string and cannot be.
     *
     * `panelalpha-cache-registry` is a name on the engine's docker network and
     * the host is not on it: `docker push` there resolves against the host's
     * own DNS, misses, and falls back to HTTPS against a plain-HTTP registry.
     *
     * Loopback needs no daemon configuration: docker treats 127.0.0.0/8 as
     * insecure by default. The loopback port belongs to the writer
     * ({@see CACHE_REGISTRY_WRITER_CONTAINER}), which shares the read-only
     * instance's storage, so an image pushed to one address is pulled from the other.
     * The writer runs in the host's network namespace and listens here only,
     * so no account can reach it; core reaches it through the host's loopback too.
     */
    public const HOST_CACHE_REGISTRY = '127.0.0.1:5000';

    /** The read-only registry's container name, which is what the pull probe asks the daemon about. */
    public const CACHE_REGISTRY_CONTAINER = 'panelalpha-cache-registry';

    /** The writable instance on the same storage, on the host's loopback only. */
    public const CACHE_REGISTRY_WRITER_CONTAINER = 'panelalpha-cache-registry-writer';

    /** What {@see loadFromHostCommand()} says when a registry is down, so callers can tell. */
    public const REGISTRY_DOWN = 'panelalpha-cache-registry or its writer is not running';

    /** Pushes hold it shared, garbage-collect exclusive; the root broker makes it beside its socket. */
    public const PUSH_LOCK = '/run/panelalpha/cache-registry.lock';

    /** {@see PUSH_LOCK}, or where PA_PUSH_LOCK puts it for the tests and the dind-test harness. */
    public static function pushLock(): string
    {
        $override = getenv('PA_PUSH_LOCK');

        return is_string($override) && $override !== '' ? $override : self::PUSH_LOCK;
    }

    /** Where registry 3.x reads its config, which garbage-collect is given. */
    private const REGISTRY_CONFIG = '/etc/distribution/config.yml';

    /** Docker Hub pull-through cache, as an account (and core) address it. */
    public const PROXY_REGISTRY = 'panelalpha-registry-proxy:5000';

    /**
     * ghcr.io pull-through cache, as an account (and core) address it. Docker mirrors only Docker Hub, so the seed
     * ladder pulls by this name and restores the plain one ({@see pullThroughRungs()}).
     */
    public const GHCR_PROXY_CONTAINER = 'panelalpha-ghcr-proxy';

    public const GHCR_PROXY_REGISTRY = self::GHCR_PROXY_CONTAINER.':5000';

    /** Upstream registry host => the container caching it for every account. */
    private const PULL_THROUGH = ['ghcr.io' => self::GHCR_PROXY_CONTAINER];

    /**
     * The host's daemon builds the Dockerfile alone, with no provenance or SBOM, so an account can pull the image.
     * Idempotent, so safe per deploy; $rebuild is prewarm's weekly refresh: same tag, fresh upstream layers.
     */
    public function hostBuildCommand(string $tag, string $dockerfile, bool $rebuild = false, bool $pull = true): string
    {
        return (new ImagePipelines)->hostBuild($tag, $dockerfile, PublishedBaseImage::ref($tag), $rebuild, $pull);
    }

    /**
     * {@see hostBuildCommand()} as root, for a deploy that waits for it.
     *
     * @return list<string>
     */
    public function hostBuildArgv(string $tag, string $dockerfile, bool $pull = true): array
    {
        return Privileged::scripts()->buildImageOnHost($tag, $dockerfile, PublishedBaseImage::ref($tag), $pull);
    }

    /**
     * {@see hostBuildCommand()} as root and detached, for a deploy that does not wait.
     *
     * @return list<string>
     */
    public function hostBuildInBackgroundArgv(string $tag, string $dockerfile): array
    {
        return Privileged::scripts()->buildImageOnHostInBackground($tag, $dockerfile, PublishedBaseImage::ref($tag));
    }

    /**
     * Pull $published and name it $tag, but only when its label holds the hash of $dockerfile: a copy built from
     * other code is removed again and the caller builds.
     */
    public function hostPullPublishedCommand(string $tag, string $published, string $dockerfile): string
    {
        return (new ImagePipelines)->hostPullPublished($tag, $published, $dockerfile);
    }

    /** Push a host image to the cache registry under its own name. */
    public function hostPublishCommand(string $image): string
    {
        return (new ImagePipelines)->hostPublish($image);
    }

    /**
     * Rebuild one of our base images on the host, then publish it. The build
     * holds only this image's {@see buildLockPath()}: two deploys needing the
     * same missing base cannot both build it (the second waits, then finds the
     * first one's image), and every other image transfer goes on meanwhile.
     * Only the push holds {@see PUSH_LOCK}, shared, which is all a
     * garbage-collect needs.
     */
    public function hostRebuildCommand(string $image, string $dockerfile): string
    {
        return (new ImagePipelines)->hostRebuild($image, $dockerfile, PublishedBaseImage::ref($image));
    }

    /**
     * {@see hostRebuildCommand()} as root.
     *
     * @return list<string>
     */
    public function hostRebuildArgv(string $image, string $dockerfile): array
    {
        return Privileged::scripts()->rebuildImageOnHost($image, $dockerfile, PublishedBaseImage::ref($image));
    }

    /** One lock file per image, in core's /tmp, where every build runs. */
    public static function buildLockPath(string $image): string
    {
        return '/tmp/panelalpha-base-build-'.sha1($image).'.lock';
    }

    /**
     * Drop manifests no tag points at, and every layer only they held. Needs
     * {@see PUSH_LOCK} held exclusive around it.
     *
     * @return list<string>
     */
    public static function garbageCollectArgv(): array
    {
        return Privileged::docker()->registryGarbageCollect(self::CACHE_REGISTRY_WRITER_CONTAINER, self::REGISTRY_CONFIG);
    }

    /**
     * Host store to account through the cache registry: push from the host,
     * pull in the account, restore the plain name. One retry, then fail with
     * the registry's own error; there is no other route. The host pushes to
     * loopback, the account pulls by the network name it already trusts.
     */
    public function loadFromHostCommand(EngineAccount $account, string $image): string
    {
        return (new ImagePipelines)->loadFromHost($account->username, $image);
    }

    /**
     * {@see loadFromHostCommand()} as root.
     *
     * @return list<string>
     */
    public function loadFromHostArgv(EngineAccount $account, string $image): array
    {
        return Privileged::scripts()->loadImageFromHost($account->username, $image);
    }

    /** What {@see seedCommand()} says when neither the cache registry nor the host has $ours. */
    public const NOT_BUILT_HERE = 'not built on this host yet';

    /**
     * Everything that gets one image into an account, as a single command that
     * prints one line saying where it came from:
     *
     *   1. the account already has it: nothing to do, nothing printed;
     *   2. the cache registry has it;
     *   3. $ours: the host built it: push, then 2. Nowhere else has it;
     *   4. a public image on a registry the engine caches (ghcr.io): through its pull-through cache;
     *   5. otherwise the account pulls it from its own registry, Docker Hub
     *      through registry-proxy. A public image never goes through the
     *      host's copy, which is the one that can be incomplete.
     *
     * Fails with the last step's error when none of them worked.
     *
     * $private skips 2 to 4: an image the project logs in for is pulled
     * straight into the account, so it never lands in a registry every other
     * account reads. $dockerConfig is the client config holding that login.
     */
    public function seedCommand(
        EngineAccount $account,
        string $image,
        bool $ours,
        bool $private = false,
        ?string $dockerConfig = null,
    ): string {
        $image = ImageTransfer::preferDigest($image);
        $img = escapeshellarg($image);
        if ($private) {
            return $this->seedLadder($account, $image, $this->loggedInPullRung($account, $image, $dockerConfig));
        }

        $rest = $ours
            ? [$this->builtOnHostRungs($account, $image, $img)]
            : [...$this->pullThroughRungs($account, $image, $img), $this->accountPullRung($account, $image)];

        return $this->seedLadder(
            $account,
            $image,
            self::cacheRegistryRung($this->fromCacheRegistry($account, $image), $img),
            ...$rest,
        );
    }

    public function operatorSeedCommand(EngineAccount $account, string $image): string
    {
        $img = escapeshellarg($image);

        return 'if '.$this->fromCacheRegistry($account, $image).'; then'
            ." printf 'Pulled base image %s from the cache registry\\n' {$img};"
            ." elif {$this->accountHas($account, $image)}; then"
            ." printf 'Kept base image %s already in the account: the cache registry did not have it\\n' {$img};"
            ." else printf 'Could not get %s into the account\\n' {$img} >&2; false; fi";
    }

    /**
     * $image as its registry's pull-through cache serves it (`panelalpha-ghcr-proxy:5000/<path>:<tag>`), or null
     * when the engine caches no registry it lives on.
     */
    public static function pullThroughRef(string $image): ?string
    {
        $slash = strpos($image, '/');
        $container = $slash === false ? null : (self::PULL_THROUGH[substr($image, 0, $slash)] ?? null);

        return $container === null ? null : $container.':5000'.substr($image, $slash);
    }

    /**
     * The rung that pulls $image through its registry's pull-through cache and restores the plain name. An uncached
     * registry, a stopped cache or a digest reference falls through to the account's own pull.
     *
     * @return list<string>
     */
    private function pullThroughRungs(EngineAccount $account, string $image, string $img): array
    {
        $ref = self::pullThroughRef($image);
        if ($ref === null) {
            return [];
        }
        $container = explode(':', $ref, 2)[0];
        $pull = "{$this->registryRunning($container)} && {$this->pullAndRename($account, $ref, $image)}";

        return [self::rung($pull, 'Pulled base image %s through the pull-through cache', $img)];
    }

    /**
     * Fails with {@see NOT_BUILT_HERE} when the host has not built $image yet:
     * not an error, the caller builds it. Every real failure says why on stderr.
     */
    private function builtOnHostRungs(EngineAccount $account, string $image, string $img): string
    {
        $hostHas = Privileged::dockerLines()->inspectImage($image);

        return " elif ! {$hostHas} >/dev/null 2>&1; then"
            ." printf '%s\\n' '".self::NOT_BUILT_HERE."' >&2; false;"
            .self::throughHostRung("{ {$this->loadFromHostCommand($account, $image)}; }", $img);
    }

    /**
     * {@see seedCommand()} for a public image the host fetches once for every
     * account: on a cache-registry miss the host pulls it, if it does not hold
     * it already, and hands it over through the registry, so the next account
     * pulls it from there. The account pulling it itself is the last rung.
     *
     * A mutable tag (`latest`) is re-pulled on the host before it is pushed,
     * so a registry miss never republishes a host copy older than the tag.
     */
    public function seedThroughHostCommand(EngineAccount $account, string $image): string
    {
        $image = ImageTransfer::preferDigest($image);
        $img = escapeshellarg($image);
        $fromCache = $this->fromCacheRegistry($account, $image);
        $fromHost = self::hostCopyCommand($image)." && { {$this->loadFromHostCommand($account, $image)}; }";

        return $this->seedLadder(
            $account,
            $image,
            self::cacheRegistryRung($fromCache, $img),
            self::throughHostRung($fromHost, $img),
            $this->accountPullRung($account, $image),
        );
    }

    /**
     * Re-pull a public base image the account already holds. A tag such as
     * `node:22-bookworm-slim` moves with every upstream patch release, security
     * fixes included, and {@see seedCommand()} never looks again once the
     * account has it. The pull goes through registry-proxy like any public
     * image. Prints whether the image changed; fails when the pull does, and
     * the caller keeps the copy the account has.
     */
    public function refreshCommand(EngineAccount $account, string $image): string
    {
        $img = escapeshellarg($image);
        $docker = $this->accountDocker($account);
        $inspect = $docker->images($image).' 2>/dev/null';

        return "before=\$({$inspect}); {$docker->pull($image)} >/dev/null"
            ." && if [ \"\$({$inspect})\" = \"\$before\" ]; then printf 'Base image %s is current\\n' {$img};"
            ." else printf 'Updated base image %s\\n' {$img}; fi";
    }

    /**
     * Whether $image has an upstream to follow: a public tag, not one of ours
     * (no registry publishes those) and not pinned to a digest.
     */
    public static function followsUpstream(string $image): bool
    {
        return ImageTransfer::isSafeImageRef($image) && ! BuiltImage::isOurs($image) && ! str_contains($image, '@');
    }

    /** The host's copy of $image, pulled when missing; a mutable tag is re-pulled first. */
    private static function hostCopyCommand(string $image): string
    {
        $docker = Privileged::dockerLines();
        $hostHas = $docker->inspectImage($image).' >/dev/null 2>&1';
        $hostPull = $docker->pullQuietly($image).' >/dev/null 2>&1';

        return self::isMutableTag($image) ? "{ {$hostPull} || {$hostHas}; }" : "{ {$hostHas} || {$hostPull}; }";
    }

    /**
     * Nothing to do when the account already has $image, else the first rung
     * that works, else a failure saying so on stderr.
     */
    private function seedLadder(EngineAccount $account, string $image, string ...$rungs): string
    {
        return "if {$this->accountHas($account, $image)}; then :;"
            .implode('', $rungs)
            ." else printf 'Could not get %s into the account\\n' ".escapeshellarg($image).' >&2; false; fi';
    }

    /** One `elif` of {@see seedLadder()}: when $condition succeeds, print $message for $img. */
    private static function rung(string $condition, string $message, string $img): string
    {
        return " elif {$condition}; then printf '{$message}\\n' {$img};";
    }

    private static function cacheRegistryRung(string $fromCache, string $img): string
    {
        return self::rung($fromCache, 'Pulled base image %s from the cache registry', $img);
    }

    private static function throughHostRung(string $fromHost, string $img): string
    {
        return self::rung($fromHost, 'Loaded base image %s from the host through the cache registry', $img);
    }

    /** The account pulls $image from its own registry. */
    private function accountPullRung(EngineAccount $account, string $image): string
    {
        return self::pullRung($this->accountDocker($account)->pull($image), $image);
    }

    /** {@see accountPullRung()} with the project's login in $dockerConfig, which the broker sends with the pull. */
    private function loggedInPullRung(EngineAccount $account, string $image, ?string $dockerConfig): string
    {
        return self::pullRung($this->accountDocker($account)->pull($image, $dockerConfig), $image);
    }

    private static function pullRung(string $pull, string $image): string
    {
        return self::rung("{$pull} >/dev/null", 'Pulled base image %s', escapeshellarg($image));
    }

    /** The account pulls $image from the cache registry and restores its plain name. */
    private function fromCacheRegistry(EngineAccount $account, string $image): string
    {
        return "{$this->registryRunning()} && {$this->pullAndRename($account, self::CACHE_REGISTRY.'/'.$image, $image)}";
    }

    /** The account pulls $ref quietly, names it $image, and drops the $ref tag. */
    private function pullAndRename(EngineAccount $account, string $ref, string $image): string
    {
        $docker = $this->accountDocker($account);

        return $docker->pull($ref).' >/dev/null 2>&1'
            .' && '.$docker->tag($ref, $image)
            .' && { '.$docker->removeImage($ref).' >/dev/null 2>&1 || true; }';
    }

    /** A tag held without its config counts as missing. */
    private function accountHas(EngineAccount $account, string $image): string
    {
        return $this->accountDocker($account)->hasImage($image).' >/dev/null 2>&1';
    }

    /** No tag or `latest`: what it names moves, unlike a dated tag or a digest. */
    private static function isMutableTag(string $image): bool
    {
        if (str_contains($image, '@')) {
            return false;
        }
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        if ($colon === false || ($slash !== false && $colon < $slash)) {
            return true;
        }

        return substr($image, $colon + 1) === 'latest';
    }

    /**
     * {@see seedCommand()} for several images, never more than $concurrency in
     * flight. Needs bash for `wait -n`; run it as ['bash', '-c', $script].
     * Best-effort: the caller checks what arrived.
     *
     * @param  list<string>  $images
     */
    public function parallelImportCommand(EngineAccount $account, array $images, int $concurrency): string
    {
        $commands = [];
        foreach ($images as $image) {
            if (is_string($image) && $image !== '' && ImageTransfer::isSafeImageRef($image)) {
                $commands[] = escapeshellarg($this->seedCommand($account, $image, false));
            }
        }
        if ($commands === []) {
            return 'true';
        }

        return Template::named('script/seed-images')->render([
            'concurrency' => max(1, min($concurrency, ImageTransfer::MAX_CONCURRENCY)),
            'commands' => implode(' ', $commands),
        ]);
    }

    /** Asked of the daemon, which answers the same from core and from the host. */
    private function registryRunning(string $container = self::CACHE_REGISTRY_CONTAINER): string
    {
        return Privileged::dockerLines()->isRunning($container).' 2>/dev/null | grep -qx true';
    }

    private function accountDocker(EngineAccount $account): RootAccountDockerLines
    {
        return Privileged::accountDockerLines($account->username);
    }

    /**
     * With the host's own docker, in its namespaces.
     *
     * @return list<string>
     */
    public function hostImageInspectArgv(string $image): array
    {
        return Privileged::probes()->hostImage($image);
    }

    /**
     * @return list<string>
     */
    public function hostImageExposedPortsArgv(string $image): array
    {
        return Privileged::docker()->imageExposedPorts($image);
    }

    /**
     * Pulls one of our images from the loopback writer under the plain name the build asks for.
     * `['true']` for a public or unsafe ref, which docker pulls itself or not at all.
     *
     * @return list<string>
     */
    public function hostEnsureImageArgv(string $image): array
    {
        if (! ImageTransfer::isSafeImageRef($image) || ! BuiltImage::isOurs($image)) {
            return ['true'];
        }

        return Privileged::host()->ensureOwnImage($image);
    }

    /**
     * Re-pulls a public image through registry-proxy, since a host build never pulls it again.
     * A failed pull keeps the old copy. `['true']` when there is nothing to do.
     *
     * @return list<string>
     */
    public function hostRefreshImageArgv(string $image): array
    {
        if (! self::followsUpstream($image)) {
            return ['true'];
        }

        return Privileged::host()->refreshPublicImage($image);
    }
}
