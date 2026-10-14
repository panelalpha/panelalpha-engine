<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Platform\Runtime\Images;
use Symfony\Component\Yaml\Yaml;

/**
 * The compose file the engine writes: the application service, whatever
 * sidecars the decision brought with it, and the volumes they need.
 */
final class GeneratedCompose
{
    public const APP_SERVICE = 'app';

    /**
     * The image the application service runs, read back out of a file this class
     * produced. Null for anything unparseable, without an app service, or for a
     * service that builds: its `image:` is a local tag no registry has.
     */
    public static function appImage(string $yaml): ?string
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (\Exception $e) {
            return null;
        }

        $service = $parsed['services'][self::APP_SERVICE] ?? null;
        if (!is_array($service) || isset($service['build'])) {
            return null;
        }

        $image = $service['image'] ?? null;

        return is_string($image) && trim($image) !== '' ? trim($image) : null;
    }

    /**
     * The label key every generated service carries, and the marker
     * {@see ComposeFileInspector} recognises a generated file by.
     *
     * The value says which generator wrote it — 'railpack', 'dockerfile',
     * 'framework-db'.
     */
    public const LABEL = 'panelalpha.generated';

    /** The Procfile process run once before the app starts, and the profile that keeps `up` from starting it. */
    public const RELEASE_PROCESS = 'release';

    public const RELEASE_PROFILE = 'panelalpha-release';

    /** {@see LABEL}'s value on a service a Procfile line added: this, then the process name. */
    public const PROCESS_LABEL_PREFIX = 'procfile-';

    /** Where a recipe's dependencies put their commands, which a Procfile line names bare. */
    private const PROCESS_PATH = '/app/.venv/bin:/app/node_modules/.bin:/app/vendor/bin';

    private const INLINE_DEPTH = 4;

    private const INDENT = 2;

    /**
     * The whole file: the app service, the decision's sidecars and its volumes.
     * Empty arrays are dumped as `[]` (`entrypoint: []`), since Compose rejects `{}`.
     *
     * @param array<string, mixed> $appService
     * @param array<string, mixed> $decision
     */
    public static function render(array $appService, array $decision = []): string
    {
        $sidecars = self::sidecars($decision);
        $compose = ['services' => [self::APP_SERVICE => self::appService($appService, $decision, $sidecars)] + $sidecars];
        $volumes = $decision['volumes'] ?? null;
        if (is_array($volumes)) {
            $compose['volumes'] = self::volumeDefinitions($volumes);
        }

        return Yaml::dump($compose, self::INLINE_DEPTH, self::INDENT, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * The app service with the restart policy and log rotation every generator needs
     * (Docker's default `no` keeps an app down after a reboot), the aliases of the
     * service it replaced, and long-form `depends_on` on its sidecars.
     *
     * @param array<string, mixed> $appService
     * @param array<string, mixed> $decision
     * @param array<string, array<string, mixed>> $sidecars
     * @return array<string, mixed>
     */
    private static function appService(array $appService, array $decision, array $sidecars): array
    {
        $appService = ServiceHardener::withRestartPolicy($appService);
        $appService = ServiceHardener::withLogRotation($appService);
        $appService = self::withReplacedServiceAliases($appService, $decision);
        if (is_array($appService['depends_on'] ?? null) && array_is_list($appService['depends_on'])) {
            $appService['depends_on'] = self::dependsOn($appService['depends_on'], $sidecars);
        }

        return $appService;
    }

    /**
     * The names the project's kept services reached the dropped app service by, as
     * network aliases: a proxy naming it in its own nginx config would otherwise
     * fail with `host not found in upstream`. {@see ServiceAliases}
     *
     * @param array<string, mixed> $appService
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function withReplacedServiceAliases(array $appService, array $decision): array
    {
        $aliases = ServiceAliases::fromDecision($decision);
        if ($aliases !== []) {
            $appService['networks'] = ServiceAliases::withAliases($appService['networks'] ?? null, $aliases);
        }

        return $appService;
    }

    /**
     * An empty definition (`db_data: {}` parses to `[]`) as null, since Compose
     * rejects `[]` for a volume.
     *
     * @param array<mixed> $volumes
     * @return array<mixed>
     */
    private static function volumeDefinitions(array $volumes): array
    {
        return array_map(static fn (mixed $v): mixed => $v === [] ? null : $v, $volumes);
    }

    /**
     * Dump a document we did not write, keeping its own `[]` vs `{}`.
     *
     * @param array<mixed> $compose
     */
    private static function dumpKeeping(string $yaml, array $compose): string
    {
        return ComposeYaml::dump($compose, $yaml, self::INLINE_DEPTH, self::INDENT);
    }

    /**
     * The project's `persist-paths` as named volumes on the app service, so
     * what the app writes there outlives a rebuild, which replaces the
     * checkout a recipe bind-mounts. Mounted over that bind; a path something
     * already mounts is left alone. Unchanged when there is nothing to add.
     *
     * @param list<string> $paths
     */
    public static function withPersistedPaths(string $yaml, array $paths): string
    {
        if ($paths === []) {
            return $yaml;
        }
        $compose = ComposeYaml::parse($yaml);
        $service = $compose['services'][self::APP_SERVICE] ?? null;
        if (!is_array($service)) {
            return $yaml;
        }

        $mounts = is_array($service['volumes'] ?? null) ? array_values($service['volumes']) : [];
        $volumes = is_array($compose['volumes'] ?? null) ? $compose['volumes'] : [];
        $persisted = self::persistedMounts($paths, $mounts, $volumes);
        if ($persisted === null) {
            return $yaml;
        }

        [$compose['services'][self::APP_SERVICE]['volumes'], $compose['volumes']] = $persisted;

        return self::dumpKeeping($yaml, $compose);
    }

    /**
     * The mounts and volume definitions with a named volume for every path not yet
     * mounted, never reusing a volume name the file already has. Null when none is added.
     *
     * @param list<string> $paths
     * @param list<mixed> $mounts
     * @param array<mixed> $volumes
     * @return array{0: list<mixed>, 1: array<mixed>}|null
     */
    private static function persistedMounts(array $paths, array $mounts, array $volumes): ?array
    {
        $taken = self::mountedTargets($mounts);
        $names = self::volumeNamesFor($paths, array_map('strval', array_keys($volumes)));
        $added = false;
        foreach ($paths as $path) {
            if (isset($taken[$path])) {
                continue;
            }
            $name = $names[$path];
            $mounts[] = $name . ':' . $path;
            $volumes[$name] ??= null;
            $taken[$path] = true;
            $added = true;
        }

        return $added ? [$mounts, $volumes] : null;
    }

    /**
     * Container paths the mounts cover, short or long syntax, without a trailing slash.
     *
     * @param list<mixed> $mounts
     * @return array<string, true>
     */
    private static function mountedTargets(array $mounts): array
    {
        $taken = [];
        foreach ($mounts as $mount) {
            $target = is_string($mount) ? (explode(':', $mount)[1] ?? null) : ($mount['target'] ?? null);
            if (is_string($target)) {
                $taken[rtrim($target, '/') ?: '/'] = true;
            }
        }

        return $taken;
    }

    /**
     * One more service per Procfile process besides `web`, each the app
     * service running the process's command in a shell: the same image or
     * build, env, volumes and dependencies, no ports. `release` is a one-shot behind a
     * profile, so `up` leaves it to the run before it. A name the file
     * already uses is skipped, and an app served by stock nginx gets none:
     * there is no runtime in it to run them. Unchanged when there are none.
     *
     * @param array<string, string> $processes name => command
     */
    public static function withProcesses(string $yaml, array $processes): string
    {
        if ($processes === []) {
            return $yaml;
        }
        $compose = ComposeYaml::parse($yaml);
        $app = $compose['services'][self::APP_SERVICE] ?? null;
        if (!is_array($app) || ($app['image'] ?? null) === Images::NGINX_IMAGE) {
            return $yaml;
        }

        $base = self::processBase($app);
        $added = false;
        foreach ($processes as $name => $command) {
            if (isset($compose['services'][$name])) {
                continue;
            }
            $compose['services'][$name] = self::processService($base, $name, $command);
            $added = true;
        }

        return $added ? self::dumpKeeping($yaml, $compose) : $yaml;
    }

    /**
     * The app service without what belongs to the app alone. A service that builds
     * loses its `image:` and gets its own cached build, since the app's tag does not
     * exist yet when `run` asks for the release.
     *
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private static function processBase(array $app): array
    {
        $base = $app;
        unset($base['ports'], $base['healthcheck'], $base['networks'], $base['container_name'], $base['hostname']);
        if (isset($base['build'])) {
            unset($base['image']);
        }

        return $base;
    }

    /**
     * One Procfile process, run in a shell of its own as Heroku does: an image
     * entrypoint like Railpack's `bash -c` would otherwise take `sh` for the script.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function processService(array $base, string $name, string $command): array
    {
        $service = $base;
        $service['labels'] = [self::LABEL => self::PROCESS_LABEL_PREFIX . $name];
        $service['entrypoint'] = ['sh', '-c'];
        $service['command'] = ['export PATH=' . self::PROCESS_PATH . ':$$PATH && ' . FrameworkService::shellCommand($command)];
        if ($name === self::RELEASE_PROCESS) {
            $service['restart'] = 'no';
            $service['profiles'] = [self::RELEASE_PROFILE];
        } else {
            $service['restart'] = 'unless-stopped';
        }

        return $service;
    }

    /**
     * The service a Procfile `release:` line became, if this compose file has one.
     *
     * @param array<mixed> $compose
     */
    public static function releaseService(array $compose): ?string
    {
        $label = self::PROCESS_LABEL_PREFIX . self::RELEASE_PROCESS;
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            if (is_array($service) && ($service['labels'][self::LABEL] ?? null) === $label) {
                return (string) $name;
            }
        }

        return null;
    }

    /** `/var/lib/grafana` -> `data-var-lib-grafana`, stable across deploys. */
    public static function volumeNameFor(string $path): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $path), '-'));

        return 'data-' . ($slug === '' ? 'root' : $slug);
    }

    /**
     * One name per path, in the order given. A path folding onto a slug already
     * used (`/app/Uploads` after `/app/uploads`) gets a short hash appended, so
     * it does not share that volume; the first keeps the plain name.
     *
     * @param list<string> $paths
     * @param list<string> $reserved names another source (a Dockerfile `VOLUME`) already uses
     * @return array<string, string> path => volume name
     */
    public static function volumeNamesFor(array $paths, array $reserved = []): array
    {
        $names = [];
        $used = array_fill_keys($reserved, true);
        foreach ($paths as $path) {
            $name = self::volumeNameFor($path);
            if (isset($used[$name])) {
                $name .= '-' . substr(hash('sha256', $path), 0, 8);
            }
            $used[$name] = true;
            $names[$path] = $name;
        }

        return $names;
    }

    /**
     * The long form, so the app waits for a sidecar that can say it is ready.
     * The short form only waits for the container to start, and an app that
     * migrates on boot then races its own database.
     *
     * @param list<mixed> $names
     * @param array<string, array<string, mixed>> $sidecars
     * @return array<string, array{condition: string}>
     */
    private static function dependsOn(array $names, array $sidecars): array
    {
        $depends = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $depends[$name] = [
                'condition' => self::hasHealthcheck($sidecars[$name] ?? []) ? 'service_healthy' : 'service_started',
            ];
        }

        return $depends;
    }

    /** @param array<string, mixed> $service */
    private static function hasHealthcheck(array $service): bool
    {
        $check = $service['healthcheck'] ?? null;
        if (!is_array($check) || ($check['disable'] ?? false) === true) {
            return false;
        }
        $test = $check['test'] ?? null;

        return $test !== null && $test !== [] && $test !== ['NONE'] && $test !== 'NONE';
    }

    /**
     * The decision's sidecars, each restarting unless it says otherwise: the app
     * needs them whether or not they publish a port.
     *
     * @param array<string, mixed> $decision
     * @return array<string, array<string, mixed>>
     */
    private static function sidecars(array $decision): array
    {
        $sidecars = [];
        foreach ((array) ($decision['sidecars'] ?? []) as $name => $sidecar) {
            if (is_string($name) && $name !== '' && is_array($sidecar)) {
                if (in_array($sidecar['restart'] ?? null, [null, '', false], true)) {
                    $sidecar['restart'] = 'unless-stopped';
                }
                $sidecars[$name] = $sidecar;
            }
        }

        return $sidecars;
    }
}
