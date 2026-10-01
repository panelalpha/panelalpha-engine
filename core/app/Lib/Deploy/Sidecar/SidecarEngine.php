<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * What kind of backing service a Compose service is.
 *
 * One question, asked in five different places before this class existed,
 * with five different answers — which is how a Postgres named "db" ended up
 * being handed to the application as a MySQL, and a memcached named "cache"
 * as a Redis. Everything that needs to know now asks here.
 *
 * Evidence is ranked, strongest first, and the first layer that answers wins:
 *
 *  1. The image family, matched exactly. This is the one unambiguous signal:
 *     postgres-backup-local is a backup job, not a database, and a substring
 *     match cannot tell the difference.
 *  2. The variables the service declares — but only the ones a server uses to
 *     initialise *itself*. A backup job and a database both carry POSTGRES_*,
 *     which is why this layer sits below the image and demands more than one
 *     init variable and no host pointing somewhere else.
 *  3. The ports the image itself declares. This is what makes an unknown
 *     vendor fork work: we may never have heard of myorg/our-postgres, but it
 *     still exposes 5432. The caller supplies these — see $observedPorts —
 *     because reading them means asking the daemon, and this class stays free
 *     of Laravel and of the shell.
 *  4. The service name. Weakest by far: half the compose files in the world
 *     call their database "db". Consulted only when nothing else answered, so
 *     a name can never overrule a recognised image.
 *
 * The catalogue itself lives in {@see SidecarDialects}, the compose-file
 * dialect in {@see ComposeService}, and which service is the application in
 * {@see ServiceRole}.
 *
 * No Laravel dependencies — unit-testable.
 */
class SidecarEngine
{
    /**
     * Base images an application is routinely built on top of. These say
     * nothing about what the service does, so a service using one may still
     * fall through to its name.
     *
     * @var list<string>
     */
    private const UNOPINIONATED_BASE_IMAGES = [
        'alpine', 'debian', 'ubuntu', 'busybox', 'scratch',
        'node', 'python', 'php', 'ruby', 'golang', 'openjdk', 'eclipse-temurin',
    ];

    /**
     * The engine a service runs, derived rather than looked up.
     *
     * The name is already in front of us: `postgres:16` contains the word
     * postgres, `POSTGRES_PASSWORD` contains it too, and a service called
     * `surrealdb` names itself. Nothing here decides whether a service is
     * *allowed* to be an engine — an image nobody has heard of resolves to
     * its own name and is configured under it.
     *
     * @param array<string, mixed> $service the Compose service definition
     * @param list<int> $observedPorts ports the image declares, from
     *        `docker image inspect`; empty when the caller could not ask
     * @return string|null the engine name, or null when the service offers no
     *         evidence at all of being one
     */
    public static function resolve(string $name, array $service, array $observedPorts = []): ?string
    {
        $candidates = self::candidates(ComposeService::of($service), $observedPorts);

        // An engine whose dialect we can actually speak outranks a name we can
        // only repeat back. internal/store:1 answering on 5432 is a Postgres,
        // whatever its image is called.
        foreach ($candidates as $candidate) {
            if (SidecarDialects::has($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? SidecarDialects::canonical(strtolower($name));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function dialects(): array
    {
        return SidecarDialects::all();
    }

    public static function forgetDialects(): void
    {
        SidecarDialects::forget();
    }

    public static function canonical(string $engine): ?string
    {
        return SidecarDialects::canonical($engine);
    }

    /**
     * @return array{engine: string, port: int, scheme: ?string, driver: ?string, default_database: ?string, env: array<string, string>}
     */
    public static function dialect(string $engine, int $observedPort = 0): array
    {
        return SidecarDialects::dialect($engine, $observedPort);
    }

    /**
     * @return list<string>
     */
    public static function initVariablesFor(string $engine): array
    {
        return SidecarDialects::initVariablesFor($engine);
    }

    public static function memoryLimitFor(string $engine): ?string
    {
        return SidecarDialects::memoryLimitFor($engine);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceOverridesFor(string $engine): array
    {
        return SidecarDialects::serviceOverridesFor($engine);
    }

    public static function portFor(string $engine): int
    {
        return SidecarDialects::portFor($engine);
    }

    /**
     * @param array<string, string> $environment already-resolved env of the service
     * @return array{prefix: ?string, username: ?string, password: ?string, database: ?string}
     */
    public static function credentials(array $environment): array
    {
        return CredentialNames::extract($environment);
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     */
    public static function isKnownDatastore(string $name, array $service, array $observedPorts = []): bool
    {
        return ServiceRole::isKnownDatastore($name, $service, $observedPorts);
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     * @param array<string, mixed> $siblings
     */
    public static function isBackingService(
        string $name,
        array $service,
        array $observedPorts = [],
        array $siblings = [],
        ?string $projectIdentity = null
    ): bool {
        return ServiceRole::isBacking($name, $service, $observedPorts, $siblings, $projectIdentity);
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, mixed> $siblings
     */
    public static function looksLikeTheApplication(
        string $name,
        array $service,
        array $siblings,
        ?string $projectIdentity = null
    ): bool {
        return ServiceRole::isApplication($name, $service, $siblings, $projectIdentity);
    }

    public static function imageIsBuildOf(string $image, ?string $projectIdentity): bool
    {
        return ServiceRole::imageIsBuildOf($image, $projectIdentity);
    }

    /**
     * @param array<string, mixed> $services
     */
    public static function servicesProvide(array $services, string $engine): bool
    {
        return ServiceRole::servicesProvide($services, $engine);
    }

    public static function imageFamily(string $image): string
    {
        return ComposeService::familyOf($image);
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     * @return list<int>
     */
    public static function allPorts(array $service, array $observedPorts = []): array
    {
        return ComposeService::of($service)->ports($observedPorts);
    }

    /**
     * The engine names the evidence suggests, strongest first.
     *
     * @param list<int> $observedPorts
     * @return list<string>
     */
    private static function candidates(ComposeService $service, array $observedPorts): array
    {
        $candidates = [
            self::fromImage($service),
            self::fromDeclaredEnvironment($service),
            self::fromPorts($service->ports($observedPorts)),
        ];

        return array_values(array_filter($candidates, static fn (?string $c): bool => $c !== null));
    }

    private static function fromImage(ComposeService $service): ?string
    {
        $family = $service->imageFamily();
        $informative = $family !== '' && !in_array($family, self::UNOPINIONATED_BASE_IMAGES, true);

        return $informative ? SidecarDialects::canonical($family) : null;
    }

    /**
     * The engine named by the service's own initialisation variables.
     *
     * `POSTGRES_USER` plus `POSTGRES_PASSWORD` says postgres without anything
     * having to know what postgres is. A `*_HOST` disqualifies the service as
     * a client of the engine rather than the engine itself.
     */
    private static function fromDeclaredEnvironment(ComposeService $service): ?string
    {
        $keys = $service->environmentKeys();
        if (CredentialNames::namesARemoteHost($service->environment())) {
            return null;
        }
        foreach (CredentialNames::serverPrefixes($keys) as $prefix) {
            if (!CredentialNames::isClientPrefix($prefix, $keys)) {
                return SidecarDialects::canonical($prefix);
            }
        }

        return null;
    }

    /**
     * Only the unambiguous ports identify an engine. A dialect listed for its
     * port number alone (minio on 9000, influxdb on 8086) is reached through
     * its image name; matching those ports the other way round would claim
     * every application that happens to serve on 9000.
     *
     * @param list<int> $ports
     */
    private static function fromPorts(array $ports): ?string
    {
        $unambiguous = SidecarDialects::unambiguousPorts();
        foreach (SidecarDialects::all() as $engine => $dialect) {
            $port = (int) ($dialect['port'] ?? 0);
            if (in_array($port, $unambiguous, true) && in_array($port, $ports, true)) {
                return (string) $engine;
            }
        }

        return null;
    }
}
