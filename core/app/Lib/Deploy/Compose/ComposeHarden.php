<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * Turning a compose file somebody wrote for their laptop into one an account
 * can run.
 *
 * The entry point for two related jobs: hardening every service in a stack
 * ({@see ServiceHardener}), and reducing a project's own compose file to the
 * backing services a deploy actually needs ({@see RuntimeSidecars}).
 *
 * No Laravel dependencies — unit-testable.
 */
class ComposeHarden
{
    /**
     * @return array<string, string>
     */
    public static function urlEnvironment(?string $publicUrl): array
    {
        return PublicUrlEnvironment::for($publicUrl);
    }

    /**
     * @param array<string, mixed> $compose
     * @param array<array-key, mixed>|null $asWritten the repository's own services, when
     *        `$compose` carries a prepared copy of them, for the one-shot decision only
     * @return array<string, mixed>
     */
    public static function apply(array $compose, ?int $accountMemoryMb = null, ?array $asWritten = null): array
    {
        if (!is_array($compose['services'] ?? null)) {
            return $compose;
        }

        // Classified against the file the repository wrote, not against what is
        // left of it here. A caller that has already prepared the services has
        // usually removed the very keys the classifier treats as evidence --
        // {@see RuntimeSidecars::keep()} strips `ports` from every service it
        // keeps -- and a published port is what vetoes the one-shot rules. Read
        // post-strip, a long-running server with a sibling on the same image
        // looks exactly like an anchor, and gets `restart: "no"`: it never
        // comes back after an exit or a host reboot.
        $oneShot = self::oneShotServices(
            is_array($asWritten) ? ['services' => $asWritten] + $compose : $compose
        );
        foreach ($compose['services'] as $name => $service) {
            if (is_array($service)) {
                if (in_array((string) $name, $oneShot, true)) {
                    $service['restart'] = 'no';
                }
                $compose['services'][$name] = ServiceHardener::harden((string) $name, $service, $accountMemoryMb);
            }
        }

        return $compose;
    }

    /**
     * One-shot services {@see apply()} gives `restart: "no"`: a job that exits
     * is not restarted, but a policy its author wrote still wins.
     *
     * @param array<string, mixed> $compose
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
     * @param array<string, mixed> $compose
     * @return array{compose: array<string, mixed>, dropped: list<string>}
     */
    public static function withoutHostIngress(array $compose): array
    {
        return HostIngress::strip($compose);
    }

    /**
     * Publishes the detected primary port when a service only `expose:`s it, so
     * the account container binds the port the DinD proxy targets ({@see PrimaryPortBinding}).
     *
     * @param array<string, mixed> $compose
     * @return array{compose: array<string, mixed>, published: ?int}
     */
    public static function withPublishedPrimaryPort(array $compose): array
    {
        return PrimaryPortBinding::apply($compose);
    }

    /**
     * Image-backed services from a local-dev compose that the app needs at
     * runtime (mysql, redis, typesense, postgres, …).
     *
     * @param bool $backingServicesOnly keep datastores only, dropping anything
     *        that looks like the application itself
     * @param callable(string): list<int>|null $imagePorts resolves an image to
     *        the ports it declares; null when nobody can ask the daemon
     * @param ?string $projectIdentity owner/repo being deployed
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function extractRuntimeSidecars(
        string $composePath,
        bool $backingServicesOnly = false,
        ?callable $imagePorts = null,
        ?string $projectIdentity = null,
        ?int $accountMemoryMb = null,
        ?string $placeholderSeed = null
    ): array {
        return RuntimeSidecars::fromFile(
            $composePath,
            $backingServicesOnly,
            $imagePorts,
            $projectIdentity,
            $accountMemoryMb,
            $placeholderSeed
        );
    }

    /**
     * As {@see extractRuntimeSidecars()}, from already-read YAML.
     *
     * @param callable(string): list<int>|null $imagePorts
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function extractRuntimeSidecarsFromYaml(
        string $raw,
        bool $backingServicesOnly = false,
        ?callable $imagePorts = null,
        ?string $projectIdentity = null,
        ?int $accountMemoryMb = null,
        ?string $placeholderSeed = null
    ): array {
        return RuntimeSidecars::fromYaml(
            $raw,
            $backingServicesOnly,
            $imagePorts,
            $projectIdentity,
            $accountMemoryMb,
            $placeholderSeed
        );
    }

    /**
     * A datastore or similar backing service, as opposed to the application.
     *
     * Needed when the stack is read from a template the repo ships: there the
     * app is usually a published image (ghcr.io/owner/app:stable), so "has an
     * image" no longer distinguishes it from a database. Running it alongside
     * the image we build from the repo would start the application twice.
     *
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     */
    public static function isBackingService(string $name, array $service, array $observedPorts = []): bool
    {
        return SidecarEngine::isBackingService($name, $service, $observedPorts);
    }
}
