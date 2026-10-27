<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
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
     * Strategies that already have a runnable image, or bind-mount the project onto
     * a stock one (PHP, a host-run project): `up` skips the build and the base-image pull,
     * since `up --build` fails on a service without `build:`.
     */
    public static function skipBuild(?string $strategy, ?string $runtime = null): bool
    {
        return $runtime === PlatformManifest::RUNTIME_NGINX
            || $runtime === PlatformManifest::RUNTIME_PHP
            || StandaloneNodeServe::isStandaloneStrategy($strategy)
            || HostRunProject::isStrategy($strategy)
            || in_array($strategy, [Strategies::STATIC, Strategies::FALLBACK, Strategies::RAILPACK], true);
    }

    /**
     * `up -d` recreates a service only when its definition changes, and for a mounted
     * project nothing does, so the old container would keep serving the previous
     * build (Nitro's `.output` is even deleted under it) or nginx config.
     */
    public static function forceRecreate(?string $strategy, ?string $runtime = null): bool
    {
        return $runtime === PlatformManifest::RUNTIME_PHP
            || $runtime === PlatformManifest::RUNTIME_NGINX
            || in_array($strategy, [Strategies::STATIC, Strategies::FALLBACK], true)
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
                './'.NginxConfig::FILENAME.':/etc/nginx/conf.d/default.conf:ro',
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
            'ports' => [self::hostPortFor($port).':'.$port],
            'restart' => 'unless-stopped',
            'labels' => [GeneratedCompose::LABEL => 'dockerfile'],
            'env_file' => ['.env'],
        ];
        $service = self::withBuiltImageTag($service, $decision);
        [$service, $decision] = self::withAppMounts($service, $decision);
        $service = self::withTerminal($service, $decision);
        [$service, $decision] = self::withDeclaredVolumes($service, $decision);

        return GeneratedCompose::render(
            $service + self::optional($decision),
            self::withoutEmptyVolumes($decision)
        );
    }

    /**
     * Tag the build with the name a sibling service refers to it by (dpaste's
     * `migration: image: app`), or Compose refuses with `pull access denied`.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withBuiltImageTag(array $service, array $decision): array
    {
        $image = self::builtImageName($decision['build_image'] ?? null);
        if ($image !== null) {
            $service['image'] = $image;
        }

        return $service;
    }

    /**
     * The volumes the file's own app service mounted, each declared: the replacement
     * serves the same app, and Compose refuses a mount of an undeclared volume.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $decision
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function withAppMounts(array $service, array $decision): array
    {
        $appMounts = self::stringMounts($decision['app_mounts'] ?? null);
        if ($appMounts === []) {
            return [$service, $decision];
        }

        $service['volumes'] = $appMounts;
        $carried = is_array($decision['volumes'] ?? null) ? $decision['volumes'] : [];
        foreach ($appMounts as $mount) {
            $carried[explode(':', $mount)[0]] ??= null;
        }
        $decision['volumes'] = $carried;

        return [$service, $decision];
    }

    /**
     * `tty` / `stdin_open` as the repository's own service set them: a CMD ending
     * in `exec bash` exits at once without a terminal.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withTerminal(array $service, array $decision): array
    {
        foreach (['tty', 'stdin_open'] as $key) {
            if (($decision[$key] ?? null) === true) {
                $service[$key] = true;
            }
        }

        return $service;
    }

    /**
     * Compose volume strings, from a decision value that may be anything.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringMounts($value): array
    {
        if (! is_array($value)) {
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
        if (! is_string($declared)) {
            return null;
        }
        $name = trim($declared);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '@')) {
            return null;
        }

        $colon = strrpos($name, ':');
        $repository = $colon === false ? $name : substr($name, 0, $colon);
        $tag = $colon === false ? null : substr($name, $colon + 1);
        if (! self::isSafeTag($repository) || ($tag !== null && ! self::isSafeTag($tag))) {
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
        $services = self::parsedServices($composeYaml);

        return self::appServiceImageName($services) ?? self::referencedBuildImageName($services);
    }

    /** @param array<mixed> $services */
    private static function appServiceImageName(array $services): ?string
    {
        $app = $services[GeneratedCompose::APP_SERVICE] ?? null;
        if (! is_array($app) || ! isset($app['build'])) {
            return null;
        }

        return self::builtImageName($app['image'] ?? null);
    }

    /**
     * The tag of the first build service a sibling's `image:` refers to. Both sides
     * are normalised, so an untagged name and `:latest` compare equal.
     *
     * @param  array<mixed>  $services
     */
    private static function referencedBuildImageName(array $services): ?string
    {
        $referenced = [];
        foreach ($services as $service) {
            if (! is_array($service) || isset($service['build'])) {
                continue;
            }
            $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
            if ($image !== null) {
                $referenced[strtolower($image)] = true;
            }
        }
        foreach ($services as $service) {
            if (! is_array($service) || ! isset($service['build'])) {
                continue;
            }
            $declared = self::builtImageName($service['image'] ?? null);
            $normalized = $declared === null ? null : ImageTransfer::normalizeImageRef($declared);
            if ($normalized !== null && isset($referenced[strtolower($normalized)])) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public static function framework(array $decision, int $port, ?string $publicUrl = null): string
    {
        return GeneratedCompose::render(FrameworkService::for($decision, $port, $publicUrl), $decision);
    }

    /** The image Railpack built in the account, outside compose: no registry has it to pull. */
    public static function railpack(string $imageName, int $port): string
    {
        return GeneratedCompose::render([
            'image' => $imageName,
            'pull_policy' => 'never',
            'ports' => ["{$port}:{$port}"],
            'restart' => 'unless-stopped',
            'labels' => [GeneratedCompose::LABEL => 'railpack'],
        ]);
    }

    /**
     * Distinct pullable images a compose file references, seeded from the host
     * cache instead of pulled through the inner daemon's nested NAT. Capped so a
     * dozen sidecars do not serialise a dozen host pulls. A name the file builds
     * itself is the build's own tag, not something a registry has.
     *
     * @param  list<string>|null  $activeProfiles  when given, a service behind
     *                                             any other `profiles:` is skipped: `compose up` will not start it
     * @param  array<string, list<?string>|string>|null  $env  when given, `image:`
     *                                                         is interpolated as Compose would ({@see resolvedImageRef()})
     * @return list<string>
     */
    public static function imageRefs(string $composeYaml, int $limit = self::IMAGE_REF_LIMIT, ?array $activeProfiles = null, ?array $env = null): array
    {
        $services = self::servicesIn($composeYaml);
        $builtHere = self::imagesBuiltHere($services, $env);

        $images = [];
        foreach ($services as $service) {
            if (isset($service['build']) || ! self::isActive($service, $activeProfiles)) {
                continue;
            }
            $image = self::serviceImageRef($service, $env);
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
     * @param  array<string, mixed>  $service
     * @param  list<string>|null  $activeProfiles  null: every service counts
     */
    private static function isActive(array $service, ?array $activeProfiles): bool
    {
        $profiles = array_filter((array) ($service['profiles'] ?? []), static fn ($p): bool => is_string($p) && trim($p) !== '');
        if ($activeProfiles === null || $profiles === [] || in_array('*', $activeProfiles, true)) {
            return true;
        }

        return array_intersect($profiles, $activeProfiles) !== [];
    }

    /**
     * Every normalized image name this compose file builds for itself.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  array<string, list<?string>|string>|null  $env
     * @return list<string> lowercase
     */
    public static function imagesBuiltHere(array $services, ?array $env = null): array
    {
        $names = [];
        foreach ($services as $service) {
            if (! is_array($service) || ! isset($service['build'])) {
                continue;
            }
            $image = self::serviceImageRef($service, $env);
            if ($image !== null) {
                $names[] = strtolower($image);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * A service's `image:`, interpolated when $env is given, else only normalised.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>|null  $env
     */
    private static function serviceImageRef(array $service, ?array $env): ?string
    {
        return $env === null
            ? ImageTransfer::normalizeImageRef($service['image'] ?? null)
            : self::resolvedImageRef($service['image'] ?? null, $env);
    }

    /**
     * An `image:` as Compose runs it: `wordpress:${WORDPRESS_VERSION:-latest}`
     * interpolated against $env ({@see ComposeInterpolation} shape), then
     * normalised. Null when the value cannot be told for certain or is not a
     * reference docker accepts.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    public static function resolvedImageRef(mixed $image, array $env = []): ?string
    {
        if (is_string($image) && str_contains($image, '$')) {
            $candidates = ComposeInterpolation::candidates(trim($image), $env);
            if ($candidates === null || count($candidates) !== 1) {
                return null;
            }
            $image = $candidates[0];
        }

        return ImageTransfer::normalizeImageRef($image);
    }

    /**
     * Does this project run our stock Node image against its own directory, with
     * nothing built?
     *
     * @param  array<string, mixed>  $decision
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
     * @param  array<string, mixed>  $decision
     */
    public static function isHostCompiled(array $decision): bool
    {
        return self::isStandaloneNodeOutput($decision) || self::isHostRunProject($decision);
    }

    /**
     * @param  array<string, mixed>  $decision
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
     * @param  array<string, string>  $args
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
     * `environment`, `depends_on` and `extra_hosts`, each only when set. Extra hosts
     * pin the account's own MySQL server, whose name resolves only on the host.
     *
     * @param  array<string, mixed>  $decision
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
        $extraHosts = ComposeValues::stringList($decision['extra_hosts'] ?? null);
        if ($extraHosts !== []) {
            $service['extra_hosts'] = $extraHosts;
        }

        return $service;
    }

    /**
     * Give every `VOLUME` the Dockerfile declares a named volume: anonymous ones are
     * orphaned by a recreate, and a bind mount would hide what the image ships there.
     * A path something already mounts is left as it is; two paths folding onto one
     * slug get a volume each.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $decision
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function withDeclaredVolumes(array $service, array $decision): array
    {
        $declared = ComposeValues::stringList($decision['dockerfile_volumes'] ?? null);
        if ($declared === []) {
            return [$service, $decision];
        }

        $mounts = ComposeValues::stringList($service['volumes'] ?? null);
        $taken = self::mountTargets($mounts);
        $volumes = is_array($decision['volumes'] ?? null) ? $decision['volumes'] : [];
        $names = GeneratedCompose::volumeNamesFor($declared);
        foreach ($declared as $path) {
            if (isset($taken[$path])) {
                continue;
            }
            $name = $names[$path];
            $mounts[] = $name.':'.$path;
            $volumes[$name] ??= null;
        }

        if ($mounts !== []) {
            $service['volumes'] = $mounts;
        }
        $decision['volumes'] = $volumes;

        return [$service, $decision];
    }

    /**
     * @param  list<string>  $mounts
     * @return array<string, true> container path => true
     */
    private static function mountTargets(array $mounts): array
    {
        $taken = [];
        foreach ($mounts as $mount) {
            $parts = explode(':', $mount);
            if (isset($parts[1])) {
                $taken[$parts[1]] = true;
            }
        }

        return $taken;
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
        return array_values(array_filter(self::parsedServices($composeYaml), 'is_array'));
    }

    /**
     * The `services:` map as written; empty when there is none or the YAML does not parse.
     *
     * @return array<mixed>
     */
    private static function parsedServices(string $composeYaml): array
    {
        try {
            $parsed = Yaml::parse($composeYaml);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
    }
}
