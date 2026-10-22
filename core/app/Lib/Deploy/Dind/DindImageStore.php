<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Template\Template;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;

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
     * ({@see \App\System\Project\Dind}), and the name resolves on
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

    /**
     * Pushes hold this shared; prewarm's tag deletion and garbage-collect hold it
     * exclusive, since a GC during an upload deletes the upload's layers. In /tmp
     * of the core container, where both run, and opened read-only by either user.
     */
    public const PUSH_LOCK = '/tmp/panelalpha-cache-registry.lock';

    /** Where registry 3.x reads its config, which garbage-collect is given. */
    private const REGISTRY_CONFIG = '/etc/distribution/config.yml';

    /** Docker Hub pull-through cache, as an account (and core) address it. */
    public const PROXY_REGISTRY = 'panelalpha-registry-proxy:5000';

    /**
     * `docker build -` takes the Dockerfile on stdin, which is all a base image
     * needs — nothing is COPYed in. Idempotent, so safe to call per deploy.
     *
     * buildx attaches provenance and SBOM manifests by default, turning the
     * result into an OCI index that `docker load` in an account cannot import;
     * suppressing them is what makes the image seedable. The retry without the
     * flags covers hosts that only have the classic builder. $rebuild is
     * prewarm's weekly refresh: same tag, fresh upstream layers.
     */
    public function hostBuildCommand(string $tag, string $dockerfile, bool $rebuild = false, bool $pull = true): string
    {
        $img = escapeshellarg($tag);
        $doc = escapeshellarg($dockerfile);
        $build = "printf '%s' {$doc} | sudo docker build" . ($pull ? ' --pull' : '');
        $always = "{$build} --provenance=false --sbom=false -t {$img} - || {$build} -t {$img} -";

        return $rebuild ? $always : "sudo docker image inspect {$img} >/dev/null 2>&1 || { {$always}; }";
    }

    /** Push a host image to the cache registry under its own name. */
    public function hostPublishCommand(string $image): string
    {
        $img = escapeshellarg($image);
        $pushRef = escapeshellarg(self::HOST_CACHE_REGISTRY . '/' . $image);

        return self::sharedLock("sudo docker tag {$img} {$pushRef} && sudo docker push -q {$pushRef} >/dev/null");
    }

    /**
     * Drop manifests no tag points at, and every layer only they held. Needs
     * {@see PUSH_LOCK} held exclusive around it.
     *
     * @return list<string>
     */
    public static function garbageCollectArgv(): array
    {
        return [
            'sudo', 'docker', 'exec', self::CACHE_REGISTRY_WRITER_CONTAINER,
            'registry', 'garbage-collect', '--delete-untagged', self::REGISTRY_CONFIG,
        ];
    }

    /**
     * Host store to account through the cache registry: push from the host,
     * pull in the account, restore the plain name. One retry, then fail with
     * the registry's own error; there is no other route. The host pushes to
     * loopback, the account pulls by the network name it already trusts.
     */
    public function loadFromHostCommand(EngineAccount $account, string $image): string
    {
        $img = escapeshellarg($image);
        $exec = $this->accountExec($account);
        $pushRef = escapeshellarg(self::HOST_CACHE_REGISTRY . '/' . $image);
        $pullRef = escapeshellarg(self::CACHE_REGISTRY . '/' . $image);

        $attempt = "sudo docker tag {$img} {$pushRef}"
            . " && sudo docker push -q {$pushRef} >/dev/null"
            . " && {$exec} docker pull -q {$pullRef} >/dev/null"
            . " && {$exec} docker tag {$pullRef} {$img}"
            . " && { {$exec} docker image rm {$pullRef} >/dev/null 2>&1 || true; }";

        $running = $this->bothRegistriesRunning();

        return "{ {$running} || { echo '" . self::REGISTRY_DOWN . "' >&2; false; }; }"
            . ' && ' . self::sharedLock("{ {$attempt}; } || { sleep 2; {$attempt}; }");
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
     *   4. otherwise the account pulls it from its own registry, Docker Hub
     *      through registry-proxy. A public image never goes through the
     *      host's copy, which is the one that can be incomplete.
     *
     * Fails with the last step's error when none of them worked.
     *
     * $private skips 2 and 3: an image the project logs in for is pulled
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
        $exec = $this->accountExec($account);
        if ($private) {
            $config = $dockerConfig === null ? '' : ' --config ' . escapeshellarg($dockerConfig);

            return self::seedLadder($exec, $img, self::accountPullRung($exec, $img, $config));
        }

        return self::seedLadder(
            $exec,
            $img,
            self::cacheRegistryRung($this->fromCacheRegistry($account, $image), $img),
            $ours ? $this->builtOnHostRungs($account, $image, $img) : self::accountPullRung($exec, $img),
        );
    }

    /**
     * Fails with {@see NOT_BUILT_HERE} when the host has not built $image yet:
     * not an error, the caller builds it. Every real failure says why on stderr.
     */
    private function builtOnHostRungs(EngineAccount $account, string $image, string $img): string
    {
        return " elif ! sudo docker image inspect -- {$img} >/dev/null 2>&1; then"
            . " printf '%s\\n' '" . self::NOT_BUILT_HERE . "' >&2; false;"
            . self::throughHostRung("{ {$this->loadFromHostCommand($account, $image)}; }", $img);
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
        $exec = $this->accountExec($account);
        $fromCache = $this->fromCacheRegistry($account, $image);
        $fromHost = self::hostCopyCommand($image, $img) . " && { {$this->loadFromHostCommand($account, $image)}; }";

        return self::seedLadder(
            $exec,
            $img,
            self::cacheRegistryRung($fromCache, $img),
            self::throughHostRung($fromHost, $img),
            self::accountPullRung($exec, $img),
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
        $exec = $this->accountExec($account);
        $id = "{$exec} docker image inspect -f '{{.Id}}' -- {$img} 2>/dev/null";

        return "before=\$({$id}); {$exec} docker pull -q {$img} >/dev/null"
            . " && if [ \"\$({$id})\" = \"\$before\" ]; then printf 'Base image %s is current\\n' {$img};"
            . " else printf 'Updated base image %s\\n' {$img}; fi";
    }

    /**
     * Whether $image has an upstream to follow: a public tag, not one of ours
     * (no registry publishes those) and not pinned to a digest.
     */
    public static function followsUpstream(string $image): bool
    {
        return ImageTransfer::isSafeImageRef($image) && !BuiltImage::isOurs($image) && !str_contains($image, '@');
    }

    /** The host's copy of $image, pulled when missing; a mutable tag is re-pulled first. */
    private static function hostCopyCommand(string $image, string $img): string
    {
        $hostHas = "sudo docker image inspect -- {$img} >/dev/null 2>&1";
        $hostPull = "sudo docker pull -q {$img} >/dev/null 2>&1";

        return self::isMutableTag($image) ? "{ {$hostPull} || {$hostHas}; }" : "{ {$hostHas} || {$hostPull}; }";
    }

    /**
     * Nothing to do when the account already has $img, else the first rung
     * that works, else a failure saying so on stderr.
     */
    private static function seedLadder(string $exec, string $img, string ...$rungs): string
    {
        return "if {$exec} docker image history -q -- {$img} >/dev/null 2>&1; then :;"
            . implode('', $rungs)
            . " else printf 'Could not get %s into the account\\n' {$img} >&2; false; fi";
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

    /** The account pulls $img from its own registry; $clientFlags go before `pull`. */
    private static function accountPullRung(string $exec, string $img, string $clientFlags = ''): string
    {
        return self::rung("{$exec} docker{$clientFlags} pull -q {$img} >/dev/null", 'Pulled base image %s', $img);
    }

    /** The account pulls $image from the cache registry and restores its plain name. */
    private function fromCacheRegistry(EngineAccount $account, string $image): string
    {
        $img = escapeshellarg($image);
        $exec = $this->accountExec($account);
        $cacheRef = escapeshellarg(self::CACHE_REGISTRY . '/' . $image);

        return "{$this->registryRunning()}"
            . " && {$exec} docker pull -q {$cacheRef} >/dev/null 2>&1"
            . " && {$exec} docker tag {$cacheRef} {$img}"
            . " && { {$exec} docker image rm {$cacheRef} >/dev/null 2>&1 || true; }";
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
     * @param list<string> $images
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

    /** Run $command holding {@see PUSH_LOCK} shared, so it never overlaps a garbage-collect. */
    private static function sharedLock(string $command): string
    {
        $lock = escapeshellarg(self::PUSH_LOCK);

        return "( touch {$lock} 2>/dev/null; exec 9<{$lock} && flock -s 9 && { {$command}; } )";
    }

    private function bothRegistriesRunning(): string
    {
        return $this->registryRunning() . ' && ' . $this->registryRunning(self::CACHE_REGISTRY_WRITER_CONTAINER);
    }

    /** Asked of the daemon, which answers the same from core and from the host. */
    private function registryRunning(string $container = self::CACHE_REGISTRY_CONTAINER): string
    {
        return 'sudo docker inspect -f "{{.State.Running}}" ' . $container
            . ' 2>/dev/null | grep -qx true';
    }

    private function accountExec(EngineAccount $account): string
    {
        $file = escapeshellarg($account->controlFileOrFail());

        return "sudo docker compose -f {$file} exec -T " . DindEngine::SERVICE;
    }

    /**
     * @return list<string>
     */
    public function listImagesArgv(): array
    {
        return ['docker', 'images', '--format', '{{.Repository}}:{{.Tag}}'];
    }

    /**
     * `history` reads the config, so a tag held without one (a multi-platform
     * image held incompletely) counts as missing; `images -q` and `inspect` both answer for it.
     *
     * @return list<string>
     */
    public function imageIdArgv(string $image): array
    {
        return ['docker', 'image', 'history', '-q', '--', $image];
    }

    /**
     * On the host: the account's own container, so a registry refresh can
     * tell a daemon.json that is bind-mounted from one an old init script
     * still writes by hand. {@see \App\System\Project\Dind\AccountTemplate::daemonJson()}
     * is only mounted into accounts created after it shipped.
     *
     * @return list<string>
     */
    public function hostAccountMountsArgv(EngineAccount $account): array
    {
        return ['sudo', 'docker', 'inspect', '--format', '{{json .Mounts}}', '--', $account->username];
    }

    /**
     * On the host: every process the account's container runs, with the PID
     * as the host sees it. The account is its own PID namespace, so that PID
     * -- not the one `docker compose exec` would show -- is the one a signal
     * sent from the host can reach.
     *
     * @return list<string>
     */
    public function hostAccountProcessesArgv(EngineAccount $account): array
    {
        return ['sudo', 'docker', 'top', $account->username, '-eo', 'pid,comm'];
    }

    /**
     * No sudo: this one goes through System::execOnHost(), which already
     * enters the host namespace as root -- where that PID exists, unlike the
     * core container's own PID namespace.
     *
     * @return list<string>
     */
    public function hostSignalDockerdArgv(int $pid): array
    {
        return ['kill', '-HUP', (string) $pid];
    }

    /**
     * @return list<string>
     */
    public function imageExposedPortsArgv(string $image): array
    {
        return ['docker', 'image', 'inspect', $image, '--format', '{{json .Config.ExposedPorts}}'];
    }

    /**
     * No sudo: this one goes through System::execOnHost(), which already
     * enters the host namespace as root.
     *
     * @return list<string>
     */
    public function hostImageInspectArgv(string $image): array
    {
        return ['docker', 'image', 'inspect', '--', $image];
    }

    /**
     * @return list<string>
     */
    public function hostImageExposedPortsArgv(string $image): array
    {
        return [
            'sudo', 'docker', 'image', 'inspect', '--format', '{{json .Config.ExposedPorts}}', '--', $image,
        ];
    }

    /**
     * Pulls one of ours from the loopback writer the host pushes to and tags
     * it back to the plain name the build asks for; a public image is left for
     * docker to pull.
     *
     * @return list<string>
     */
    public function hostEnsureImageArgv(string $image): array
    {
        if (!ImageTransfer::isSafeImageRef($image) || !BuiltImage::isOurs($image)) {
            return ['true'];
        }

        $img = escapeshellarg($image);
        $pullRef = escapeshellarg(self::HOST_CACHE_REGISTRY . '/' . $image);
        $running = $this->bothRegistriesRunning();

        return [
            'sh', '-c',
            "docker image inspect -- {$img} >/dev/null 2>&1 && exit 0; "
            . "if {$running}; then "
            . "docker pull -q {$pullRef} >/dev/null && docker tag {$pullRef} {$img} && exit 0; "
            . 'fi; exit 0',
        ];
    }

    /**
     * A host build runs a public image by its plain name and never pulls it
     * again, so the host's copy is whatever was pulled first. Re-pull it,
     * through registry-proxy where the host mirrors Docker Hub. Never fatal:
     * a failed pull keeps that copy.
     *
     * @return list<string>
     */
    public function hostRefreshImageArgv(string $image): array
    {
        if (!self::followsUpstream($image)) {
            return ['true'];
        }

        return ['sh', '-c', 'docker pull -q ' . escapeshellarg($image) . ' >/dev/null 2>&1; exit 0'];
    }
}
