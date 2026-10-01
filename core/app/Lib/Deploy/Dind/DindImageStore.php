<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Template\Template;

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
 * registry, Docker Hub going through panelalpha-registry-proxy. There is no
 * `docker save | docker load`: it copied incomplete containerd-store images
 * without complaint (#156, #229).
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
     * Every push failed that way, the guard below caught it, and the whole
     * registry path never ran — which looked like the registry not being
     * installed.
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
     * flags covers hosts that only have the classic builder.
     */
    public function hostBuildCommand(string $tag, string $dockerfile, bool $rebuild = false, bool $pull = true): string
    {
        $img = escapeshellarg($tag);
        $doc = escapeshellarg($dockerfile);
        $build = "printf '%s' {$doc} | sudo docker build" . ($pull ? ' --pull' : '');
        $always = "{$build} --provenance=false --sbom=false -t {$img} - || {$build} -t {$img} -";

        // $rebuild is prewarm's weekly refresh: same tag, fresh upstream layers.
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
     * the registry's own error; there is no other route.
     */
    public function loadFromHostCommand(EngineAccount $account, string $image): string
    {
        $img = escapeshellarg($image);
        $exec = $this->accountExec($account);
        // Two addresses for one registry: the host pushes to loopback, the
        // account pulls by the network name it already trusts.
        $pushRef = escapeshellarg(self::HOST_CACHE_REGISTRY . '/' . $image);
        $pullRef = escapeshellarg(self::CACHE_REGISTRY . '/' . $image);

        $attempt = "sudo docker tag {$img} {$pushRef}"
            . " && sudo docker push -q {$pushRef} >/dev/null"
            . " && {$exec} docker pull -q {$pullRef} >/dev/null"
            . " && {$exec} docker tag {$pullRef} {$img}"
            . " && { {$exec} docker image rm {$pullRef} >/dev/null 2>&1 || true; }";

        $running = $this->registryRunning() . ' && ' . $this->registryRunning(self::CACHE_REGISTRY_WRITER_CONTAINER);

        return "{ {$running} || { echo '" . self::REGISTRY_DOWN . "' >&2; false; }; }"
            . ' && ' . self::sharedLock("{ {$attempt}; } || { sleep 2; {$attempt}; }");
    }

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
     */
    public function seedCommand(EngineAccount $account, string $image, bool $ours): string
    {
        $img = escapeshellarg($image);
        $exec = $this->accountExec($account);
        $cacheRef = escapeshellarg(self::CACHE_REGISTRY . '/' . $image);

        $fromCache = "{$this->registryRunning()}"
            . " && {$exec} docker pull -q {$cacheRef} >/dev/null 2>&1"
            . " && {$exec} docker tag {$cacheRef} {$img}"
            . " && { {$exec} docker image rm {$cacheRef} >/dev/null 2>&1 || true; }";
        $fromHost = "sudo docker image inspect -- {$img} >/dev/null 2>&1"
            . " && { {$this->loadFromHostCommand($account, $image)}; }";

        $script = "if {$exec} docker image history -q -- {$img} >/dev/null 2>&1; then :;"
            . " elif {$fromCache}; then printf 'Pulled base image %s from the cache registry\\n' {$img};";
        $script .= $ours
            ? " elif {$fromHost}; then printf 'Loaded base image %s from the host through the cache registry\\n' {$img};"
            : " elif {$exec} docker pull -q {$img} >/dev/null; then printf 'Pulled base image %s\\n' {$img};";

        return $script . " else printf 'Could not get %s into the account\\n' {$img} >&2; false; fi";
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
     * @return list<string>
     */
    public function imageIdArgv(string $image): array
    {
        // `history` reads the config, so a tag `save | load` left without one (a
        // multi-platform image held incompletely) counts as missing and is pulled
        // again; `images -q` and `inspect` both answer for it.
        return ['docker', 'image', 'history', '-q', '--', $image];
    }

    /**
     * Inside the account: make its daemon trust the cache registry and mirror
     * Docker Hub through registry-proxy, keeping everything else in its
     * daemon.json. Prints `changed` when it had to, so the caller reloads.
     * Accounts created before either entry existed never got it: their
     * daemon.json is written once, at creation.
     *
     * @return list<string>
     */
    public function registryConfigArgv(): array
    {
        $script = <<<'PY'
import json, sys
path = '/etc/docker/daemon.json'
try:
    config = json.load(open(path))
except Exception:
    sys.exit(3)
trusted = config.get('insecure-registries', [])
mirrors = config.get('registry-mirrors', [])
changed = False
for registry in (CACHE, PROXY):
    if registry not in trusted:
        trusted.append(registry)
        changed = True
if MIRROR not in mirrors:
    mirrors.insert(0, MIRROR)
    changed = True
if changed:
    config['insecure-registries'] = trusted
    config['registry-mirrors'] = mirrors
    with open(path, 'w') as f:
        json.dump(config, f)
print('changed' if changed else 'ok')
PY;
        $values = "CACHE = '" . self::CACHE_REGISTRY . "'\nPROXY = '" . self::PROXY_REGISTRY
            . "'\nMIRROR = 'http://" . self::PROXY_REGISTRY . "'\n";

        return ['python3', '-c', $values . $script];
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
}
