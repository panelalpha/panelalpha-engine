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
     * The image the application service runs, read back out of a file this
     * class produced — here because this class decided where that image sits.
     *
     * Null for anything unparseable or without an app service; callers read
     * that as "no better answer than the one I already had".
     */
    public static function appImage(string $yaml): ?string
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (\Exception $e) {
            return null;
        }

        $service = $parsed['services'][self::APP_SERVICE] ?? null;
        // A service that builds has no pullable runtime image. Its `image:` is
        // only the local tag the build is given — there is nothing at that
        // name on any registry — and reading it back would have the snapshot
        // provision a tag only this deploy can create, so the pre-pull it
        // drives asks Docker Hub for the project's own build and is refused.
        // Null here means "no better answer than the one already frozen",
        // which for a build service is the honest one.
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
     * 'framework-db'. The key is what must not drift: seven places wrote it
     * out by hand, and renaming it anywhere but all of them at once would
     * leave the inspector treating engine output as a file the customer
     * wrote.
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
     * @param array<string, mixed> $appService
     * @param array<string, mixed> $decision
     */
    public static function render(array $appService, array $decision = []): string
    {
        // Defaulted here rather than in each generator, because forgetting it
        // fails silently: Docker's own default is `no`, so the app comes up
        // on the deploy that created it and never again. staticNginx() was
        // the one caller that forgot, and its sites stayed down after a host
        // reboot while every other platform's came back. Same rule as the
        // hardener: only a service that publishes a port gets the default.
        $appService = ServiceHardener::withRestartPolicy($appService);
        $appService = ServiceHardener::withLogRotation($appService);
        // The names the project's own kept services reach the application by.
        // The file's app service was dropped and this one replaced it under
        // the name `app`, so a proxy in front — which names the old service in
        // its own nginx config and not through `depends_on` — would otherwise
        // refuse to start with `host not found in upstream`. Docker's network
        // aliases are put on the app service here, in the one place every
        // generator goes through, because the alternative is rewriting a
        // configuration we do not own. {@see ServiceAliases}
        $aliases = ServiceAliases::fromDecision($decision);
        if ($aliases !== []) {
            $appService['networks'] = ServiceAliases::withAliases($appService['networks'] ?? null, $aliases);
        }
        $sidecars = self::sidecars($decision);
        if (is_array($appService['depends_on'] ?? null) && array_is_list($appService['depends_on'])) {
            $appService['depends_on'] = self::dependsOn($appService['depends_on'], $sidecars);
        }

        $compose = ['services' => [self::APP_SERVICE => $appService] + $sidecars];
        $volumes = $decision['volumes'] ?? null;
        if (is_array($volumes)) {
            $compose['volumes'] = $volumes;
        }

        return Yaml::dump($compose, self::INLINE_DEPTH, self::INDENT);
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
        $taken = [];
        foreach ($mounts as $mount) {
            $target = is_string($mount) ? (explode(':', $mount)[1] ?? null) : ($mount['target'] ?? null);
            if (is_string($target)) {
                $taken[rtrim($target, '/') ?: '/'] = true;
            }
        }

        $volumes = is_array($compose['volumes'] ?? null) ? $compose['volumes'] : [];
        $added = false;
        foreach ($paths as $path) {
            if (isset($taken[$path])) {
                continue;
            }
            $name = self::volumeNameFor($path);
            $mounts[] = $name . ':' . $path;
            $volumes[$name] ??= null;
            $taken[$path] = true;
            $added = true;
        }
        if (!$added) {
            return $yaml;
        }

        $compose['services'][self::APP_SERVICE]['volumes'] = $mounts;
        $compose['volumes'] = $volumes;

        return Yaml::dump($compose, self::INLINE_DEPTH, self::INDENT);
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

        $base = $app;
        unset($base['ports'], $base['healthcheck'], $base['networks'], $base['container_name'], $base['hostname']);
        // Its own build of the same Dockerfile, cached: a tag the app builds is
        // not there yet when `run` asks for the release.
        if (isset($base['build'])) {
            unset($base['image']);
        }

        $added = false;
        foreach ($processes as $name => $command) {
            if (isset($compose['services'][$name])) {
                continue;
            }
            $service = $base;
            $service['labels'] = [self::LABEL => self::PROCESS_LABEL_PREFIX . $name];
            // In a shell of its own, as Heroku runs a Procfile line: an image
            // entrypoint like Railpack's `bash -c` would otherwise take `sh` for
            // the whole script.
            $service['entrypoint'] = ['sh', '-c'];
            $service['command'] = ['export PATH=' . self::PROCESS_PATH . ':$$PATH && ' . FrameworkService::shellCommand($command)];
            if ($name === self::RELEASE_PROCESS) {
                $service['restart'] = 'no';
                $service['profiles'] = [self::RELEASE_PROFILE];
            } else {
                $service['restart'] = 'unless-stopped';
            }
            $compose['services'][$name] = $service;
            $added = true;
        }

        return $added ? Yaml::dump($compose, self::INLINE_DEPTH, self::INDENT) : $yaml;
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
     * The long form, so the app waits for a sidecar that can say it is ready.
     * The short form only waits for the container to start, and an app that
     * migrates on boot then races its own database (engine#187).
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
     * @param array<string, mixed> $decision
     * @return array<string, array<string, mixed>>
     */
    private static function sidecars(array $decision): array
    {
        $sidecars = [];
        foreach ((array) ($decision['sidecars'] ?? []) as $name => $sidecar) {
            if (is_string($name) && $name !== '' && is_array($sidecar)) {
                // The app needs what the engine put beside it, port or not; a
                // one-shot the hardener marked `no` keeps that.
                if (in_array($sidecar['restart'] ?? null, [null, '', false], true)) {
                    $sidecar['restart'] = 'unless-stopped';
                }
                $sidecars[$name] = $sidecar;
            }
        }

        return $sidecars;
    }
}
