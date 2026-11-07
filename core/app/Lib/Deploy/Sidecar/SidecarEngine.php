<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * What kind of backing service a Compose service is, from the evidence strongest first: image family,
 * the service's own init variables, the image's ports, then its name. Catalogue: {@see SidecarDialects}.
 */
class SidecarEngine
{
    /**
     * Base images an application is routinely built on, which say nothing about what the service does.
     *
     * @var list<string>
     */
    private const UNOPINIONATED_BASE_IMAGES = [
        'alpine', 'debian', 'ubuntu', 'busybox', 'scratch',
        'node', 'python', 'php', 'ruby', 'golang', 'openjdk', 'eclipse-temurin',
    ];

    /**
     * The engine a service runs, derived rather than looked up; an engine whose dialect we speak
     * outranks a name we can only repeat back.
     *
     * @param  array<string, mixed>  $service  the Compose service definition
     * @param  list<int>  $observedPorts  ports the image declares; empty when the caller could not ask
     * @return string|null the engine name, or null when the service offers no evidence of being one
     */
    public static function resolve(string $name, array $service, array $observedPorts = []): ?string
    {
        $candidates = self::candidates(ComposeService::of($service), $observedPorts);

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
     * @param  array<string, string>  $environment  already-resolved env of the service
     * @return array{prefix: ?string, username: ?string, password: ?string, database: ?string}
     */
    public static function credentials(array $environment): array
    {
        return CredentialNames::extract($environment);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     */
    public static function isKnownDatastore(string $name, array $service, array $observedPorts = []): bool
    {
        return ServiceRole::isKnownDatastore($name, $service, $observedPorts);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     * @param  array<string, mixed>  $siblings
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
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $siblings
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
     * @param  array<string, mixed>  $services
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
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     * @return list<int>
     */
    public static function allPorts(array $service, array $observedPorts = []): array
    {
        return ComposeService::of($service)->ports($observedPorts);
    }

    /**
     * The engine names the evidence suggests, strongest first.
     *
     * @param  list<int>  $observedPorts
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
        $informative = $family !== '' && ! in_array($family, self::UNOPINIONATED_BASE_IMAGES, true);

        return $informative ? SidecarDialects::canonical($family) : null;
    }

    /** The engine named by the service's own initialisation variables; a `*_HOST` makes it a client instead. */
    private static function fromDeclaredEnvironment(ComposeService $service): ?string
    {
        $keys = $service->environmentKeys();
        if (CredentialNames::namesARemoteHost($service->environment())) {
            return null;
        }
        foreach (CredentialNames::serverPrefixes($keys) as $prefix) {
            if (! CredentialNames::isClientPrefix($prefix, $keys)) {
                return SidecarDialects::canonical($prefix);
            }
        }

        return null;
    }

    /**
     * Only the unambiguous ports identify an engine; the rest (minio's 9000) would claim ordinary apps.
     *
     * @param  list<int>  $ports
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
