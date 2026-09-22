<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\Platform\Strategies;
use Symfony\Component\Yaml\Yaml;

/**
 * The docker-compose.yml the engine writes for a detected strategy: a repo's
 * Dockerfile, a platform the engine builds, a static site from disk, or an
 * image Railpack produced. Shared parts live in GeneratedCompose.
 */
class DeployCompose
{
    private const STATIC_HOST_PORT = '8080:80';

    private const HTTP_HOST_PORT = 8080;

    /** Images seeded from the host cache per compose file, at most. */
    private const IMAGE_REF_LIMIT = 6;

    /**
     * Strategies that already have a runnable image, or bind-mount nginx: `up`
     * should skip the build and the base-image pull.
     */
    public static function skipBuild(?string $strategy, ?string $runtime = null): bool
    {
        // PHP bind-mounts the project onto the shared base image, so there is no
        // build context and `up --build` fails on a service without `build:`.
        return $runtime === PlatformManifest::RUNTIME_NGINX
            || $runtime === PlatformManifest::RUNTIME_PHP
            || StandaloneNodeServe::isStandaloneStrategy($strategy)
            // Same as PHP: stock image, project mounted, no build context.
            || HostRunProject::isStrategy($strategy)
            || in_array($strategy, [Strategies::STATIC, Strategies::FALLBACK, Strategies::RAILPACK], true);
    }

    /**
     * `up -d` recreates a service only when its definition changes; for PHP and a
     * mounted Node project nothing does — same image, mount and env — so the old
     * container keeps running the previous entrypoint or build. The code is a
     * bind mount, the lifecycle is not.
     */
    public static function forceRecreate(?string $strategy, ?string $runtime = null): bool
    {
        // A mounted Node project: nothing changes either, and `next start` read
        // its build output at boot, so the old process serves the previous build.
        // Nitro (Nuxt, TanStack Start) deletes and recreates `.output` on every
        // build, so a container left running keeps a bind mount of the removed
        // directory and answers 500 for every static file.
        return $runtime === PlatformManifest::RUNTIME_PHP
            || HostRunProject::isStrategy($strategy)
            || StandaloneNodeServe::isStandaloneStrategy($strategy);
    }

    /**
     * Skip the inner-Docker reclaim probe (`df` + `docker buildx du`). Static
     * HTML and Vite/Astro nginx output are small builds; the probe itself is
     * often slower than the compile. PHP and SSR still reclaim.
     */
    public static function skipReclaimBeforeBuild(?string $strategy, ?string $runtime = null): bool
    {
        return self::skipBuild($strategy) || $runtime === PlatformManifest::RUNTIME_NGINX;
    }

    /**
     * The nginx that serves a project directory as it stands. The config is
     * mounted because the image default serves only `index.html`, and the sites
     * that reach this recipe have a differently named front page. A missing mount
     * source would make Docker create a directory where nginx expects a file.
     */
    public static function staticNginx(): string
    {
        return GeneratedCompose::render([
            'image' => Images::NGINX_IMAGE,
            'ports' => [self::STATIC_HOST_PORT],
            'volumes' => [
                './:/usr/share/nginx/html/:ro',
                './' . NginxConfig::FILENAME . ':/etc/nginx/conf.d/default.conf:ro',
            ],
            'labels' => [GeneratedCompose::LABEL => DetectProjectStrategy::COMPOSE_GENERATED_LABEL],
        ]);
    }

    /**
     * The repository builds its own image but still needs the rest of its stack:
     * the database, the cache and the environment pointing at them live outside
     * the Dockerfile, and $decision carries them in the shape framework() uses.
     *
     * @param array{
     *   env?: array<string, string|int>,
     *   sidecars?: array<string, array<string, mixed>>,
     *   volumes?: array<string, mixed>,
     *   depends_on?: list<string>,
     * } $decision
     */
    public static function dockerfile(string $dockerfile, int $port, array $decision = []): string
    {
        $service = [
            'build' => self::buildContext($dockerfile, ComposeValues::stringMap($decision['build_args'] ?? null)),
            'ports' => [self::hostPortFor($port) . ':' . $port],
            'restart' => 'unless-stopped',
            'labels' => [GeneratedCompose::LABEL => 'dockerfile'],
            'env_file' => ['.env'],
        ];

        // A sibling naming the image it builds (dpaste's `migration: image: app`)
        // must resolve to the tag this build produces, or Compose refuses with
        // `pull access denied for app` before the build runs.
        $image = self::builtImageName($decision['build_image'] ?? null);
        if ($image !== null) {
            $service['image'] = $image;
        }

        // Volumes the file's own app service mounted: the replacement serves the
        // same app, and without them dpaste restart-loops on
        // `unable to open database file`.
        $appMounts = self::stringMounts($decision['app_mounts'] ?? null);
        if ($appMounts !== []) {
            $service['volumes'] = $appMounts;
            // Each has to be declared, or compose refuses the file with
            // `service "app" refers to undefined volume`.
            $carried = is_array($decision['volumes'] ?? null) ? $decision['volumes'] : [];
            foreach ($appMounts as $mount) {
                $carried[explode(':', $mount)[0]] ??= null;
            }
            $decision['volumes'] = $carried;
        }

        [$service, $decision] = self::withDeclaredVolumes($service, $decision);

        return GeneratedCompose::render(
            $service + self::optional($decision),
            self::withoutEmptyVolumes($decision)
        );
    }

    /**
     * Compose volume strings, from a decision value that may be anything.
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function stringMounts($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $mounts = [];
        foreach ($value as $mount) {
            if (is_string($mount) && trim($mount) !== '') {
                $mounts[] = trim($mount);
            }
        }

        return $mounts;
    }

    /**
     * The local tag a repo's own compose gives the image it builds, when its app
     * service declares one. Only a name with no registry in it. A trailing `:tag`
     * is kept — `image: myapp:latest` beside a `build:` names no registry, since a
     * registry reference needs a `/` — and travels with the name verbatim.
     */
    private static function builtImageName(mixed $declared): ?string
    {
        if (!is_string($declared)) {
            return null;
        }
        $name = trim($declared);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '@')) {
            return null;
        }

        $colon = strrpos($name, ':');
        $repository = $colon === false ? $name : substr($name, 0, $colon);
        $tag = $colon === false ? null : substr($name, $colon + 1);
        if (!self::isSafeTag($repository) || ($tag !== null && !self::isSafeTag($tag))) {
            return null;
        }

        return $name;
    }

    private static function isSafeTag(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name) === 1;
    }

    /**
     * The tag a repository's own compose gives the image it builds, when another
     * service in that file refers to it by name: Compose will not start a service
     * whose `image:` names something nothing provides. The app service is tried
     * first, then any build service whose bare tag a sibling references.
     */
    public static function builtImageNameFromYaml(string $composeYaml): ?string
    {
        try {
            $parsed = Yaml::parse($composeYaml);
        } catch (\Throwable) {
            return null;
        }
        $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        $app = $services[GeneratedCompose::APP_SERVICE] ?? null;
        if (is_array($app) && isset($app['build'])) {
            $declared = self::builtImageName($app['image'] ?? null);
            if ($declared !== null) {
                return $declared;
            }
        }

        $referenced = [];
        foreach ($services as $service) {
            if (!is_array($service) || isset($service['build'])) {
                continue;
            }
            $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
            if ($image !== null) {
                $referenced[strtolower($image)] = true;
            }
        }
        foreach ($services as $service) {
            if (!is_array($service) || !isset($service['build'])) {
                continue;
            }
            $declared = self::builtImageName($service['image'] ?? null);
            // Normalised like the references, so an untagged name and `:latest`
            // compare equal.
            $normalized = $declared === null ? null : ImageTransfer::normalizeImageRef($declared);
            if ($normalized !== null && isset($referenced[strtolower($normalized)])) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decision
     */
    public static function framework(array $decision, int $port, ?string $publicUrl = null): string
    {
        return GeneratedCompose::render(FrameworkService::for($decision, $port, $publicUrl), $decision);
    }

    public static function railpack(string $imageName, int $port): string
    {
        return GeneratedCompose::render([
            'image' => $imageName,
            'ports' => ["{$port}:{$port}"],
            'restart' => 'unless-stopped',
            'labels' => [GeneratedCompose::LABEL => 'railpack'],
        ]);
    }

    /**
     * Distinct pullable images a compose file references, seeded from the host
     * cache instead of pulled through the inner daemon's nested NAT. Capped so a
     * dozen sidecars do not serialise a dozen host pulls.
     *
     * @return list<string>
     */
    public static function imageRefs(string $composeYaml, int $limit = self::IMAGE_REF_LIMIT): array
    {
        $services = self::servicesIn($composeYaml);
        // A name the same file builds is the image this build produces, not a
        // registry to fetch (dpaste's `migration: image: app`): pre-pulling it
        // asked Docker Hub for `app:latest`. The only reliable signal that an
        // image is local is that the file builds it.
        $builtHere = self::imagesBuiltHere($services);

        $images = [];
        foreach ($services as $service) {
            if (isset($service['build'])) {
                continue;
            }
            $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
            if ($image === null || in_array(strtolower($image), $builtHere, true) || in_array($image, $images, true)) {
                continue;
            }
            $images[] = $image;
            if (count($images) >= $limit) {
                break;
            }
        }

        return $images;
    }

    /**
     * Every normalized image name this compose file builds for itself.
     *
     * @param list<array<string, mixed>> $services
     * @return list<string> lowercase
     */
    private static function imagesBuiltHere(array $services): array
    {
        $names = [];
        foreach ($services as $service) {
            if (!isset($service['build'])) {
                continue;
            }
            $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
            if ($image !== null) {
                $names[] = strtolower($image);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Does this project run our stock Node image against its own directory, with
     * nothing built?
     *
     * @param array<string, mixed> $decision
     */
    public static function isHostRunProject(array $decision): bool
    {
        return HostRunProject::isStrategy(
            is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null
        );
    }

    /**
     * Is this project compiled on the host instead of into an image, either as a
     * bundled server or as a mounted project directory? No Dockerfile is written
     * and the install and build run on the host.
     *
     * @param array<string, mixed> $decision
     */
    public static function isHostCompiled(array $decision): bool
    {
        return self::isStandaloneNodeOutput($decision) || self::isHostRunProject($decision);
    }

    /**
     * @param array<string, mixed> $decision
     */
    public static function isStandaloneNodeOutput(array $decision): bool
    {
        return StandaloneNodeServe::isStandaloneStrategy(
            is_string($decision['strategy'] ?? null) ? $decision['strategy'] : null
        );
    }

    /**
     * The `build:` value: a bare context path when neither the file name nor a
     * build arg forces the mapping form. `args` appears only when there are some.
     *
     * @param array<string, string> $args
     * @return array<string, mixed>|string `.` when the file is the default one
     */
    private static function buildContext(string $dockerfile, array $args = []): array|string
    {
        if ($dockerfile === 'Dockerfile' && $args === []) {
            return '.';
        }

        $build = ['context' => '.'];
        if ($dockerfile !== 'Dockerfile') {
            $build['dockerfile'] = $dockerfile;
        }
        if ($args !== []) {
            $build['args'] = $args;
        }

        return $build;
    }

    /** 80 is the proxy's own port; a container asking for it gets 8080. */
    private static function hostPortFor(int $port): int
    {
        return $port === 80 ? self::HTTP_HOST_PORT : $port;
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function optional(array $decision): array
    {
        $service = [];
        $env = ComposeValues::stringMap($decision['env'] ?? null);
        if ($env !== []) {
            $service['environment'] = $env;
        }
        $depends = ComposeValues::stringList($decision['depends_on'] ?? null);
        if ($depends !== []) {
            $service['depends_on'] = $depends;
        }
        // A database on the account's own MySQL server resolves on the host and
        // nowhere inside the account's nested Docker, so the name is pinned to an
        // address here.
        $extraHosts = ComposeValues::stringList($decision['extra_hosts'] ?? null);
        if ($extraHosts !== []) {
            $service['extra_hosts'] = $extraHosts;
        }

        return $service;
    }

    /**
     * Give every `VOLUME` the Dockerfile declares a named volume: Docker's
     * anonymous ones are not in `~/project`, not backed up, and orphaned by a
     * container recreate. A bind mount would hide what the image ships at that
     * path, where a volume copies it out on first use. Vaultwarden also refuses to
     * start on the anonymous form, which it recognises in `/proc/self/mountinfo`.
     *
     * A path something already mounts is left as it is.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $decision
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function withDeclaredVolumes(array $service, array $decision): array
    {
        $declared = ComposeValues::stringList($decision['dockerfile_volumes'] ?? null);
        if ($declared === []) {
            return [$service, $decision];
        }

        $mounts = ComposeValues::stringList($service['volumes'] ?? null);
        $taken = [];
        foreach ($mounts as $mount) {
            $parts = explode(':', $mount);
            if (isset($parts[1])) {
                $taken[$parts[1]] = true;
            }
        }

        $volumes = is_array($decision['volumes'] ?? null) ? $decision['volumes'] : [];
        foreach ($declared as $path) {
            if (isset($taken[$path])) {
                continue;
            }
            $name = self::volumeNameFor($path);
            $mounts[] = $name . ':' . $path;
            $volumes[$name] ??= null;
        }

        if ($mounts !== []) {
            $service['volumes'] = $mounts;
        }
        $decision['volumes'] = $volumes;

        return [$service, $decision];
    }

    /** `/var/lib/grafana` -> `data-var-lib-grafana`, stable across deploys. */
    private static function volumeNameFor(string $path): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $path), '-'));

        return 'data-' . ($slug === '' ? 'root' : $slug);
    }

    private static function withoutEmptyVolumes(array $decision): array
    {
        $volumes = $decision['volumes'] ?? null;
        if ($volumes === null || $volumes === []) {
            unset($decision['volumes']);
        }

        return $decision;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function servicesIn(string $composeYaml): array
    {
        try {
            $parsed = Yaml::parse($composeYaml);
        } catch (\Throwable) {
            return [];
        }
        $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        return array_values(array_filter($services, 'is_array'));
    }
}
