<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * Which service in a compose file is the application, and which are the
 * things it depends on. Asked this way round: a curated list of known
 * datastores only describes software that already existed when it was written.
 */
final class ServiceRole
{
    /**
     * Names projects give a job that runs to completion, and its setup verbs.
     * Both must match for the rule.
     */
    private const JOB_NAMES = [
        'migrate', 'migration', 'migrations',
        'init', 'setup', 'seed', 'seeder',
        'collectstatic', 'assets', 'asset-compile',
        'create-admin', 'createadmin',
    ];

    private const JOB_VERBS = [
        'migrate', 'migration', 'collectstatic', 'seed', 'createadmin',
        'create-superuser', 'createsuperuser', 'setup', 'init',
    ];

    /**
     * Whether a service is a one-shot job and not the application: a job often
     * shares the app's image (dpaste's `migration` runs `image: app`). The name
     * must be in JOB_NAMES and the command a setup verb; a published port,
     * `expose`, healthcheck or `deploy` block vetoes it.
     *
     * @param  array<string, mixed>  $service
     */
    public static function isJobService(string $name, array $service): bool
    {
        if (! in_array(strtolower($name), self::JOB_NAMES, true)) {
            return false;
        }
        foreach (['ports', 'expose', 'healthcheck', 'deploy'] as $key) {
            if (! empty($service[$key])) {
                return false;
            }
        }
        if (isset($service['network_mode']) || isset($service['pid']) || isset($service['network'])) {
            return false;
        }
        $command = $service['command'] ?? $service['entrypoint'] ?? null;

        return is_string($command) && self::runsASetupVerb($command);
    }

    /** Case-insensitive substring search for the words a setup command uses. */
    private static function runsASetupVerb(string $command): bool
    {
        $command = strtolower($command);
        foreach (self::JOB_VERBS as $verb) {
            if (str_contains($command, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this is recognisably a datastore. Defaults to "no": it is used
     * where a wrong yes does damage (choosing the proxy's port). isBacking()
     * defaults to "yes", where a wrong no would drop the database.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     */
    public static function isKnownDatastore(string $name, array $service, array $observedPorts = []): bool
    {
        $engine = SidecarEngine::resolve($name, $service, $observedPorts);
        if ($engine !== null && SidecarDialects::has($engine)) {
            return true;
        }

        $ports = ComposeService::of($service)->ports($observedPorts);

        return array_intersect($ports, SidecarDialects::unambiguousPorts()) !== [];
    }

    /**
     * Whether a service is a dependency the application needs at runtime.
     * Everything not shown to be the application is kept and started.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     * @param  array<string, mixed>  $siblings  every service in the file, for the
     *                                          depends_on shape; omit when asking about a service in isolation
     */
    public static function isBacking(
        string $name,
        array $service,
        array $observedPorts = [],
        array $siblings = [],
        ?string $projectIdentity = null
    ): bool {
        if (self::isKnownDatastore($name, $service, $observedPorts)) {
            return true;
        }
        if (ComposeService::of($service)->image() === '') {
            return false;
        }

        return ! self::isApplication($name, $service, $siblings, $projectIdentity);
    }

    /**
     * The application is the service that reaches for the others: it declares
     * `depends_on: [db, redis]` and dependencies are depended upon. Settled
     * earlier by the image being the published build of the repository, or
     * being shared with a sibling (web, worker, scheduler: one app three ways).
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $siblings
     */
    public static function isApplication(
        string $name,
        array $service,
        array $siblings,
        ?string $projectIdentity = null
    ): bool {
        // A catalogued datastore (or redis-http gateway) is infrastructure
        // even when it `depends_on` another sidecar.
        if (self::isKnownDatastore($name, $service)) {
            return false;
        }
        // Checked before the image tests below, which would call a one-shot
        // job sharing the app's image the application.
        if (self::isJobService($name, $service)) {
            return false;
        }
        if (self::imageIsBuildOf(ComposeService::of($service)->image(), $projectIdentity)) {
            return true;
        }
        if ($siblings === []) {
            return false;
        }

        return self::sharesImageWithSibling($name, $service, $siblings)
            || self::isTopOfTheStack($name, $service, $siblings);
    }

    /**
     * Whether an image is the published build of the repository being
     * deployed. `owner/repo` matches ghcr.io/owner/repo, docker.io/owner/repo
     * and owner/repo alike, on any tag.
     */
    public static function imageIsBuildOf(string $image, ?string $projectIdentity): bool
    {
        $identity = strtolower(trim((string) $projectIdentity, " \t/"));
        $image = strtolower(trim($image));
        if ($identity === '' || $image === '' || ! str_contains($identity, '/')) {
            return false;
        }

        [$owner, $name] = self::ownerAndName($image);
        [$wantOwner, $wantName] = array_slice(explode('/', $identity), -2);

        // owner/repo published as owner/repo, or as any registry's copy of it.
        if ($owner === $wantOwner && $name === $wantName) {
            return true;
        }

        // Single-name images are common (docmost/docmost). Only when the repo
        // name is the whole image name, so acme/db never matches repo `db`.
        return $owner === '' && $name === $wantName && $wantOwner === $wantName;
    }

    /**
     * @param  array<string, mixed>  $services
     */
    public static function servicesProvide(array $services, string $engine): bool
    {
        foreach ($services as $name => $service) {
            if (is_array($service) && SidecarEngine::resolve((string) $name, $service) === $engine) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string} owner (empty when single-name), name
     */
    private static function ownerAndName(string $image): array
    {
        $segments = explode('/', explode('@', $image, 2)[0]);
        $name = explode(':', (string) array_pop($segments), 2)[0];

        return [$segments === [] ? '' : (string) array_pop($segments), $name];
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $siblings
     */
    private static function sharesImageWithSibling(string $name, array $service, array $siblings): bool
    {
        $image = strtolower(ComposeService::of($service)->image());
        if ($image === '') {
            return false;
        }

        foreach (self::otherServices($name, $siblings) as $other) {
            if (strtolower(ComposeService::of($other)->image()) === $image) {
                return true;
            }
        }

        return false;
    }

    /**
     * Depends on services in this file, and nothing in it depends on it.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $siblings
     */
    private static function isTopOfTheStack(string $name, array $service, array $siblings): bool
    {
        $dependsOn = ComposeService::of($service)->dependencyNames();
        $known = array_map('strtolower', array_keys($siblings));
        if (array_intersect($dependsOn, $known) === []) {
            return false;
        }

        $key = strtolower($name);
        foreach (self::otherServices($name, $siblings) as $other) {
            if (in_array($key, ComposeService::of($other)->dependencyNames(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $siblings
     * @return list<array<string, mixed>>
     */
    private static function otherServices(string $name, array $siblings): array
    {
        $key = strtolower($name);
        $others = [];
        foreach ($siblings as $otherName => $other) {
            if (is_array($other) && strtolower((string) $otherName) !== $key) {
                $others[] = $other;
            }
        }

        return $others;
    }
}
