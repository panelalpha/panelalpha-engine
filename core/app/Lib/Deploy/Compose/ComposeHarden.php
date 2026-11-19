<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\System\Project\Dind\LxcfsProc;

/**
 * Turns a laptop compose file into one an account can run: hardening every service ({@see ServiceHardener})
 * and reducing a project's own file to the backing services a deploy needs ({@see RuntimeSidecars}).
 */
class ComposeHarden
{
    /** Why {@see applyReporting()} removes a source, for the deploy log. */
    public const REMOVED_SOURCE = 'Removed from the run file (a host path, outside the project, or a directory the engine cannot check)';

    /**
     * The URL aliases for an image the repository wrote, without the HTTPS/SSL flags, the project's own
     * path-prefix keys, or SERVER_NAME, which turns on Caddy's automatic HTTPS and a 308 loop.
     *
     * @param  list<?string>  $envFiles  the project's `.env` / `.env.example`
     * @return array<string, string>
     */
    public static function urlEnvironment(?string $publicUrl, array $envFiles = []): array
    {
        return array_diff_key(
            PublicUrlEnvironment::for($publicUrl, false),
            ['SERVER_NAME' => true],
            array_flip(PublicUrlEnvironment::pathPrefixKeysIn($envFiles))
        );
    }

    /**
     * @param  array<string, mixed>  $compose
     * @param  array<array-key, mixed>|null  $asWritten  the repository's own services, when
     *                                                   `$compose` carries a prepared copy of them, for the one-shot decision only
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @return array<string, mixed>
     */
    public static function apply(array $compose, ?int $accountMemoryMb = null, ?array $asWritten = null, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        return self::applyReporting($compose, $accountMemoryMb, $asWritten, $env, $accountUser, $projectDir)['compose'];
    }

    /**
     * As {@see apply()}, with one line per mount source, secret, config or volume option it removed.
     * One-shots are classified on the file as written: a prepared copy has lost the ports that veto them.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<array-key, mixed>|null  $asWritten
     * @param  array<string, list<?string>|string>  $env
     * @param  list<string>  $procFiles  lxcfs files the account serves ({@see withProcMounts()})
     * @param  (callable(string): list<string>)|null  $imageEnvironment  an image's `Config.Env`
     * @return array{compose: array<string, mixed>, removed: list<string>}
     */
    public static function applyReporting(array $compose, ?int $accountMemoryMb = null, ?array $asWritten = null, array $env = [], ?string $accountUser = null, ?string $projectDir = null, array $procFiles = [], ?callable $imageEnvironment = null): array
    {
        [$compose, $removed] = ServiceHardener::withoutUnsafeFileSources($compose, $env, $accountUser, $projectDir);
        if (! is_array($compose['services'] ?? null)) {
            return ['compose' => $compose, 'removed' => $removed];
        }

        $oneShot = self::oneShotServices(
            is_array($asWritten) ? ['services' => $asWritten] + $compose : $compose
        );
        $publishers = self::webPublishers($compose['services']);
        $needed = ServiceReferences::needed($compose['services']);
        foreach ($compose['services'] as $name => $service) {
            if (is_array($service)) {
                $service = self::withRestartPolicy((string) $name, $service, $oneShot, $needed);
                $keepLoopback = array_diff($publishers, [$name]) !== [];
                foreach (ServiceHardener::forbiddenMounts($service, $env, $accountUser, $projectDir) as $mount) {
                    $removed[] = "{$name}: volume {$mount}";
                }
                $compose['services'][$name] = ServiceHardener::harden((string) $name, $service, $accountMemoryMb, $keepLoopback, $env, $accountUser, $projectDir, $imageEnvironment);
            }
        }
        [$compose, $entries] = ServiceHardener::withoutVolumeDriverOptions($compose);

        return ['compose' => self::withProcMounts($compose, $procFiles), 'removed' => [...$removed, ...$entries]];
    }

    /**
     * @param  array<array-key, mixed>  $services
     * @return list<array-key>
     */
    private static function webPublishers(array $services): array
    {
        return array_keys(array_filter(
            $services,
            static fn ($service): bool => is_array($service) && ServiceHardener::publishesWebPort($service)
        ));
    }

    /**
     * A one-shot is not restarted; a service another one needs publishes no port and must still come back.
     *
     * @param  array<string, mixed>  $service
     * @param  list<string>  $oneShot
     * @param  list<string>  $needed
     * @return array<string, mixed>
     */
    private static function withRestartPolicy(string $name, array $service, array $oneShot, array $needed): array
    {
        if (in_array($name, $oneShot, true)) {
            $service['restart'] = 'no';
        } elseif (in_array($name, $needed, true) && in_array($service['restart'] ?? null, [null, '', false], true)) {
            $service['restart'] = 'unless-stopped';
        }

        return $service;
    }

    /**
     * lxcfs's /proc files bound into every service, after hardening (which removes host paths); a service that
     * mounts the target keeps its own. No `read_only`: the account's own mount of {@see LxcfsProc::PROC_DIR} is
     * read-only already and every bind of it stays so, while a read-only bind makes the daemon remount it, which
     * sysbox refuses when compose copies a `configs:`/`secrets:` content into the container before it starts.
     *
     * @param  array<string, mixed>  $compose
     * @param  list<string>  $procFiles  names under {@see LxcfsProc::PROC_DIR} the account has
     * @return array<string, mixed>
     */
    public static function withProcMounts(array $compose, array $procFiles): array
    {
        if ($procFiles === [] || ! is_array($compose['services'] ?? null)) {
            return $compose;
        }
        foreach ($compose['services'] as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $volumes = is_array($service['volumes'] ?? null) ? $service['volumes'] : [];
            $taken = array_map(self::mountTarget(...), $volumes);
            foreach ($procFiles as $file) {
                if (! in_array("/proc/{$file}", $taken, true)) {
                    $volumes[] = ['type' => 'bind', 'source' => LxcfsProc::PROC_DIR."/{$file}", 'target' => "/proc/{$file}"];
                }
            }
            $compose['services'][$name]['volumes'] = array_values($volumes);
        }

        return $compose;
    }

    private static function mountTarget(mixed $volume): ?string
    {
        if (is_array($volume)) {
            return is_string($volume['target'] ?? null) ? rtrim($volume['target'], '/') : null;
        }
        if (! is_string($volume)) {
            return null;
        }
        $parts = explode(':', $volume);

        return rtrim($parts[count($parts) > 1 ? 1 : 0], '/');
    }

    /**
     * One-shot services {@see apply()} gives `restart: "no"`; a policy its author wrote still wins.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function oneShotServices(array $compose): array
    {
        return array_values(array_filter(
            OneShotServices::in($compose),
            static fn (string $name): bool => in_array($compose['services'][$name]['restart'] ?? null, [null, '', false], true)
        ));
    }

    /**
     * Drops the host's reverse proxy and its external networks ({@see HostIngress}).
     *
     * @param  array<string, mixed>  $compose
     * @return array{compose: array<string, mixed>, dropped: list<string>}
     */
    public static function withoutHostIngress(array $compose): array
    {
        return HostIngress::strip($compose);
    }

    /**
     * Publishes the detected primary port when a service only `expose:`s it ({@see PrimaryPortBinding}).
     *
     * @param  array<string, mixed>  $compose
     * @return array{compose: array<string, mixed>, published: ?int}
     */
    public static function withPublishedPrimaryPort(array $compose): array
    {
        return PrimaryPortBinding::apply($compose);
    }

    /**
     * Image-backed services from a local-dev compose that the app needs at runtime (mysql, redis, …).
     *
     * @param  bool  $backingServicesOnly  keep datastores only, dropping anything
     *                                     that looks like the application itself
     * @param  callable(string): list<int>|null  $imagePorts  resolves an image to
     *                                                        the ports it declares; null when nobody can ask the daemon
     * @param  ?string  $projectIdentity  owner/repo being deployed
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function extractRuntimeSidecars(
        string $composePath,
        bool $backingServicesOnly = false,
        ?callable $imagePorts = null,
        ?string $projectIdentity = null,
        ?int $accountMemoryMb = null,
        ?string $placeholderSeed = null,
        ?SidecarPasswords $passwords = null,
        array $env = [],
        ?string $accountUser = null,
        ?string $projectDir = null
    ): array {
        return RuntimeSidecars::fromFile(
            $composePath,
            $backingServicesOnly,
            $imagePorts,
            $projectIdentity,
            $accountMemoryMb,
            $placeholderSeed,
            $passwords,
            $env,
            $accountUser,
            $projectDir
        );
    }

    /**
     * As {@see extractRuntimeSidecars()}, from already-read YAML.
     *
     * @param  callable(string): list<int>|null  $imagePorts
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @param  list<string>  $rootBuildNames  names the repository's own compose files give its root build
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function extractRuntimeSidecarsFromYaml(
        string $raw,
        bool $backingServicesOnly = false,
        ?callable $imagePorts = null,
        ?string $projectIdentity = null,
        ?int $accountMemoryMb = null,
        ?string $placeholderSeed = null,
        ?SidecarPasswords $passwords = null,
        array $env = [],
        ?string $accountUser = null,
        ?string $projectDir = null,
        array $rootBuildNames = []
    ): array {
        return RuntimeSidecars::fromYaml(
            $raw,
            $backingServicesOnly,
            $imagePorts,
            $projectIdentity,
            $accountMemoryMb,
            $placeholderSeed,
            $passwords,
            $env,
            $accountUser,
            $projectDir,
            $rootBuildNames
        );
    }

    /**
     * A datastore or similar backing service, as opposed to the application, which in a shipped template
     * is often a published image too.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     */
    public static function isBackingService(string $name, array $service, array $observedPorts = []): bool
    {
        return SidecarEngine::isBackingService($name, $service, $observedPorts);
    }
}
