<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\Lib\Deploy\Sidecar\ServiceRole;
use Symfony\Component\Yaml\Yaml;

/**
 * The services a project's own compose file contributes to a deploy: its runtime
 * datastores, and the environment pointing the application at them. Workstation
 * services, published host ports and networks that do not exist in an account
 * are dropped; what survives is hardened and has its credentials pinned.
 *
 * @phpstan-type SidecarExtract array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>, app_mounts: list<string>, build_image: ?string, app_aliases: list<string>, dropped_mounts?: list<string>}
 */
final class RuntimeSidecars
{
    /** @var array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>, app_mounts: list<string>, build_image: ?string, app_aliases: list<string>} */
    private const EMPTY = [
        'services' => [], 'volumes' => [], 'env' => [], 'app_env' => [],
        'app_mounts' => [], 'build_image' => null, 'app_aliases' => [],
    ];

    /** Sail's `laravel.test`: other services routinely depend on it, it never deploys. */
    private const WORKSTATION_APP = 'laravel.test';

    /** Name words of a service that runs the app for a test suite (Zerobyte's `zerobyte-e2e`). */
    private const TEST_VARIANT_WORDS = ['e2e', 'test', 'tests', 'testing', 'ci', 'cypress', 'playwright'];

    /** @var array<string, array<string, mixed>> */
    private array $kept = [];

    /** @var array<string, true> lowercase names of services left out */
    private array $dropped = [];

    /** @var list<string> `service: mount` binds taken off kept services */
    private array $droppedMounts = [];

    /** @var array<string, string> env harvested from services that were dropped */
    private array $harvested = [];

    /**
     * The workstation app service's own env, kept apart from `env` so it ranks
     * below what the strategy generates.
     *
     * @var array<string, string>
     */
    private array $appEnv = [];

    /** @var array<string, list<int>> ports each kept service declared, before hardening strips them */
    private array $ports = [];

    /**
     * Named volumes the file's own app service mounts, taken before it is dropped.
     *
     * @var list<string>
     */
    private array $appMounts = [];

    /**
     * Names the dropped application went by, collected while reading so the
     * aliases can be settled once every service has been seen.
     *
     * @var list<string>
     */
    private array $appServiceNames = [];

    /**
     * Service names and `container_name`s the generated `app` answers to, so a
     * kept sibling's own config (an nginx `proxy_pass`) still resolves.
     *
     * @var list<string>
     */
    private array $appAliases = [];

    /**
     * @param array<string, mixed> $services the file's `services:` block
     * @param array<string, mixed> $declaredVolumes its `volumes:` block
     * @param bool $backingServicesOnly keep datastores only, dropping anything
     *        that looks like the application itself
     * @param callable(string): list<int>|null $imagePorts resolves an image
     *        reference to the ports it declares; null when nobody can ask
     * @param ?string $projectIdentity owner/repo being deployed, so its own
     *        published image is recognised as the application
     * @param ?string $placeholderSeed per-account secret `${VAR:?}` credentials
     *        derive from; null leaves them unset
     * @param ?SidecarPasswords $passwords a datastore password nobody set;
     *        null keeps the legacy `app`
     * @param array<string, list<?string>|string> $env what compose may interpolate the kept services with
     */
    private function __construct(
        private readonly array $services,
        private readonly array $declaredVolumes,
        private readonly bool $backingServicesOnly,
        private readonly mixed $imagePorts,
        private readonly ?string $projectIdentity,
        private readonly ?int $accountMemoryMb = null,
        private readonly ?string $placeholderSeed = null,
        private readonly ?SidecarPasswords $passwords = null,
        private readonly array $env = [],
        private readonly ?string $accountUser = null,
        private readonly ?string $projectDir = null
    ) {
    }

    /**
     * @param array<string, list<?string>|string> $env
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function fromFile(
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
        $raw = is_file($composePath) && is_readable($composePath) ? @file_get_contents($composePath) : null;

        return is_string($raw) && $raw !== ''
            ? self::fromYaml($raw, $backingServicesOnly, $imagePorts, $projectIdentity, $accountMemoryMb, $placeholderSeed, $passwords, $env, $accountUser, $projectDir)
            : self::EMPTY;
    }

    /**
     * Customer project files are often unreadable by www-data, so the caller
     * sudo-copies and hands the text over.
     *
     * @param array<string, list<?string>|string> $env
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    public static function fromYaml(
        string $raw,
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
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return self::EMPTY;
        }

        $extractor = new self(
            $parsed['services'],
            is_array($parsed['volumes'] ?? null) ? $parsed['volumes'] : [],
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

        $result = $extractor->extract();
        // The tag the file builds its application under, so the deploy builds
        // under that name. Null for a template, whose `app` is a published image.
        $result['build_image'] = $backingServicesOnly
            ? null
            : DeployCompose::builtImageNameFromYaml($raw);

        return $result;
    }



    /**
     * @return array{services: array<string, mixed>, volumes: array<string, mixed>}|null
     */
    private static function parse(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed : null;
    }

    /**
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    private function extract(): array
    {
        foreach ($this->services as $name => $service) {
            $this->consider((string) $name, $service);
        }
        $this->dropAlternativeDatabases();
        $this->clearOfAppService();
        foreach ($this->kept as $name => $service) {
            $this->kept[$name] = ServiceDependencies::withoutDropped($service, $this->dropped);
        }
        $this->collectAppAliases();

        return $this->assemble();
    }

    /**
     * @param mixed $service
     */
    private function consider(string $name, $service): void
    {
        if (!is_array($service)) {
            $this->drop($name, []);

            return;
        }
        // The file's own app service, replaced by a build of the same repo: it
        // is dropped here, but its volumes are needed by the replacement.
        $isApp = ServiceRole::isApplication($name, $service, $this->services, $this->projectIdentity);

        if ($this->isOptIn($service) || $this->isWorkstationOnly($name, $service) || !$this->hasImage($service)
            || $this->isBuiltHere($name, $service)) {
            if ($isApp && !self::isTestVariant($name)) {
                $this->appMounts = self::namedMountsOf($service, array_keys($this->declaredVolumes));
                // The replacement is built from the same repository, so the
                // dropped service's env still applies (dpaste's DATABASE_URL).
                $this->appEnv += SidecarCredentials::envFromWorkstationAppService(
                    $service,
                    $this->placeholderSeed
                );
            }
            // A proxy in front names this service in its own config, so the
            // generated `app` has to answer to the name. Settled in assemble().
            if ($this->isReplacedByOurBuild($name, $service)) {
                $containerName = $service['container_name'] ?? null;
                $this->appServiceNames = ServiceAliases::normalised(array_merge(
                    $this->appServiceNames,
                    [$name, is_string($containerName) ? $containerName : '']
                ));
            }
            $this->drop($name, $service);

            return;
        }

        $observedPorts = $this->observedPorts($service);
        // Captured before hardening strips `ports:` below: the published port is
        // evidence of what the service is.
        $this->ports[$name] = SidecarEngine::allPorts($service, $observedPorts);

        if ($this->backingServicesOnly
            && (!$this->isBacking($name, $service, $observedPorts) || $this->isNamedAfterTheProject($name, $service, $observedPorts))) {
            $this->drop($name, $service);

            return;
        }

        $this->keep($name, $service);
    }

    /**
     * @param array<string, mixed> $service
     */
    private function drop(string $name, array $service): void
    {
        $this->dropped[strtolower($name)] = true;
        $this->harvested += SidecarCredentials::envFromDroppedAppService($service);
        // The app we build takes this service's place, so it keeps its settings;
        // mailpit and vite do not.
        if (!DevServices::isDevSidecar($name, $service) && !self::isTestVariant($name)
            && ComposeFileInspector::isWorkstationAppService($service)) {
            $this->appEnv += SidecarCredentials::envFromWorkstationAppService($service, $this->placeholderSeed);
        }
    }

    /**
     * @param array<string, mixed> $service
     */
    private function keep(string $name, array $service): void
    {
        unset($service['ports'], $service['networks'], $service['extra_hosts'], $service['profiles']);
        $service = $this->withoutDependencyBinds($name, $service);
        if ($this->placeholderSeed !== null && isset($service['environment'])) {
            $service['environment'] = SidecarCredentials::withRequiredSecrets($service['environment'], $this->placeholderSeed);
        }

        $this->kept[$name] = ServiceDependencies::withoutDropped(
            $service,
            $this->dropped + [self::WORKSTATION_APP => true]
        );
    }

    /**
     * A bind from `vendor/` or `node_modules/` is the workstation's installed
     * dependencies, which a deployment's checkout never has (Sail's MySQL
     * init script lives in require-dev laravel/sail). Docker would create a
     * directory at the missing path and the entrypoint fails on it.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function withoutDependencyBinds(string $name, array $service): array
    {
        if (!is_array($service['volumes'] ?? null)) {
            return $service;
        }
        $kept = [];
        foreach ($service['volumes'] as $volume) {
            $source = trim(is_array($volume) ? (string) ($volume['source'] ?? '') : explode(':', (string) $volume, 2)[0]);
            // A path, never a named volume called `vendor`: those carry no slash.
            if (preg_match('#^(\./)*(vendor|node_modules)(/|$)#', $source) === 1 && str_contains($source, '/')) {
                $this->droppedMounts[] = $name . ': ' . (is_array($volume) ? $source : (string) $volume);
                continue;
            }
            $kept[] = $volume;
        }
        if ($kept === []) {
            unset($service['volumes']);
        } else {
            $service['volumes'] = $kept;
        }

        return $service;
    }

    /**
     * A file that offers the app a choice of SQL database (Koillection's
     * template runs postgres and mysql, its app uses one) keeps only the one
     * the app's own environment names. With no such evidence all are kept:
     * a spare database costs memory, a dropped one breaks the app.
     */
    private function dropAlternativeDatabases(): void
    {
        $engines = [];
        foreach ($this->kept as $name => $service) {
            $engine = SidecarEngine::resolve((string) $name, $service, $this->ports[$name] ?? []);
            if ($engine !== null && SidecarEngine::dialect($engine)['driver'] !== null) {
                $engines[(string) $name] = $engine;
            }
        }
        if (count(array_unique($engines)) < 2) {
            return;
        }

        $named = array_values(array_unique(array_intersect_key($engines, $this->namedByDroppedServices(array_keys($engines)))));
        if (count($named) !== 1) {
            return;
        }
        foreach ($engines as $name => $engine) {
            if ($engine !== $named[0]) {
                unset($this->kept[$name], $this->ports[$name]);
                $this->dropped[strtolower($name)] = true;
            }
        }
    }

    /**
     * Which of $candidates a dropped service's environment points at as a host
     * (`DB_HOST=postgresql`, `postgres://u:p@postgresql:5432/db`).
     *
     * @param list<string> $candidates
     * @return array<string, true>
     */
    private function namedByDroppedServices(array $candidates): array
    {
        $values = [];
        foreach ($this->services as $name => $service) {
            if (isset($this->kept[$name]) || !is_array($service)) {
                continue;
            }
            $values = array_merge($values, array_values(SidecarCredentials::environmentMap($service['environment'] ?? null)));
        }

        $named = [];
        foreach ($candidates as $candidate) {
            $host = '#(^|@|//)' . preg_quote(strtolower($candidate), '#') . '($|[:/?])#';
            foreach ($values as $value) {
                if (preg_match($host, strtolower($value)) === 1) {
                    $named[$candidate] = true;
                    break;
                }
            }
        }

        return $named;
    }

    /**
     * A kept sidecar named `app` would clash with the generated app, so it moves
     * to a free name and every reference follows.
     */
    private function clearOfAppService(): void
    {
        foreach (array_keys($this->kept) as $name) {
            $name = (string) $name;
            if (strcasecmp($name, GeneratedCompose::APP_SERVICE) !== 0
                || ServiceRole::isApplication($name, $this->kept[$name], $this->services, $this->projectIdentity)) {
                continue;
            }
            $to = $this->freeName($name . '-sidecar');
            $kept = [];
            foreach ($this->kept as $other => $service) {
                $kept[(string) $other === $name ? $to : $other] = ServiceDependencies::renamed($service, $name, $to);
            }
            $this->kept = $kept;
            $this->ports[$to] = $this->ports[$name] ?? [];
            unset($this->ports[$name]);
        }
    }

    private function freeName(string $base): string
    {
        $taken = array_map(
            static fn ($n): string => strtolower((string) $n),
            array_merge(array_keys($this->services), array_keys($this->kept))
        );
        $candidate = $base;
        for ($i = 2; in_array(strtolower($candidate), $taken, true); $i++) {
            $candidate = $base . '-' . $i;
        }

        return $candidate;
    }

    /**
     * A build of the app set up for a test run: its settings (rate limiting
     * off, a test CA, e2e origins) must not reach the deployed app.
     */
    private static function isTestVariant(string $name): bool
    {
        $words = preg_split('/[._-]+/', strtolower($name)) ?: [];

        return array_intersect($words, self::TEST_VARIANT_WORDS) !== [];
    }

    /**
     * `build:` means the image comes from this repo, not a sidecar image. A
     * catalogued datastore is still pulled.
     *
     * @param array<string, mixed> $service
     */
    private function isBuiltHere(string $name, array $service): bool
    {
        return isset($service['build']) && !ServiceRole::isKnownDatastore($name, $service);
    }

    /**
     * Whether this deploy's build takes the service's place, so the generated
     * `app` has to answer to its name: a kept sibling that reaches it by name
     * carries the reference in its own configuration file.
     *
     * @param array<string, mixed> $service
     */
    private function isReplacedByOurBuild(string $name, array $service): bool
    {
        // A dev sidecar that builds (vite serves the same source with a dev
        // server) is not replaced by the app, so its name is not aliased to it.
        if (DevServices::isDevSidecar($name, $service)) {
            return false;
        }

        return (isset($service['build']) || self::isUnresolvedImage($service))
            && !ServiceRole::isKnownDatastore($name, $service);
    }

    /**
     * Settles the names the generated app answers to, once the whole file has
     * been read.
     */
    private function collectAppAliases(): void
    {
        if ($this->kept === [] || $this->appServiceNames === []) {
            return;
        }

        // An alias a kept service already answers to would resolve to two
        // containers, so the kept names win.
        $taken = [];
        foreach ($this->kept as $keptName => $keptService) {
            $taken[strtolower((string) $keptName)] = true;
            $keptContainer = is_array($keptService) ? ($keptService['container_name'] ?? null) : null;
            if (is_string($keptContainer) && $keptContainer !== '') {
                $taken[strtolower($keptContainer)] = true;
            }
        }

        $this->appAliases = array_values(array_filter(
            $this->appServiceNames,
            static fn (string $alias): bool => !isset($taken[strtolower($alias)])
        ));
    }

    /**
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    private function assemble(): array
    {
        $volumes = NamedVolumes::usedBy($this->kept, $this->declaredVolumes);
        $hardened = ComposeHarden::apply(
            ['services' => $this->kept, 'volumes' => $volumes],
            $this->accountMemoryMb,
            // keep() already stripped `ports`, so the one-shot rules read the
            // original file to see them.
            $this->services,
            $this->env,
            $this->accountUser,
            $this->projectDir
        );
        $services = is_array($hardened['services'] ?? null) ? $hardened['services'] : [];

        $env = [];
        foreach ($services as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $ports = $this->ports[$name] ?? [];
            $services[$name] = SidecarCredentials::pinSidecarCredentials((string) $name, $service, $ports, $this->passwords);
            // Union keeping what is set: of two candidates the first declared wins.
            $env += SidecarCredentials::envForSidecar((string) $name, $services[$name], $ports);
        }

        return [
            'services' => $services,
            'volumes' => is_array($hardened['volumes'] ?? null) ? $hardened['volumes'] : $volumes,
            'env' => $env + $this->harvested,
            'app_env' => $this->appEnv,
            'app_mounts' => $this->appMounts,
            'app_aliases' => $this->appAliases,
            'dropped_mounts' => $this->droppedMounts,
        ];
    }

    /**
     * A `profiles:` gate is opt-in: `docker compose up` without `--profile`
     * never starts the service, so adopting it deploys something nobody asked
     * for. OpenCart gates postgres, redis, memcached and adminer that way.
     *
     * @param array<string, mixed> $service
     */
    private function isOptIn(array $service): bool
    {
        $profiles = $service['profiles'] ?? null;
        if (is_array($profiles)) {
            return $profiles !== [];
        }

        return is_string($profiles) && trim($profiles) !== '';
    }

    /**
     * @param array<string, mixed> $service
     */
    private function isWorkstationOnly(string $name, array $service): bool
    {
        return DevServices::isDevSidecar($name, $service)
            || ComposeFileInspector::isWorkstationAppService($service)
            // A stock image running the checkout from a bind (BookStack's node
            // asset watcher) is workstation tooling too, not a backing service.
            // A job on the image this file builds (dpaste's migration) stays.
            || (ComposeFileInspector::mountsWholeProjectRoot($service)
                && !ServiceRole::isKnownDatastore($name, $service)
                && !$this->runsAnImageThisFileBuilds($service));
    }

    /**
     * @param array<string, mixed> $service
     */
    private function runsAnImageThisFileBuilds(array $service): bool
    {
        $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
        if ($image === null) {
            return false;
        }
        foreach ($this->services as $other) {
            if (is_array($other) && isset($other['build'])
                && ImageTransfer::normalizeImageRef($other['image'] ?? null) === $image
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function hasImage(array $service): bool
    {
        return trim((string) ($service['image'] ?? '')) !== '' && !self::isUnresolvedImage($service);
    }

    /**
     * `image: ${IMAGE}` is whatever a CI job built from this repository, and
     * with nothing to set it compose reads "neither an image nor a build
     * context" and rejects the project (foodsoft's docker-compose.ci.yml).
     *
     * @param array<string, mixed> $service
     */
    private static function isUnresolvedImage(array $service): bool
    {
        $image = trim((string) ($service['image'] ?? ''));
        if ($image === '' || !str_contains($image, '$')) {
            return false;
        }
        // `${VAR:?msg}` has no value to fall back on either.
        $image = (string) preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*:?\?[^}]*\}/', '', $image);

        return trim(EnvVarDefault::resolve($image)) === '';
    }

    /**
     * @param array<string, mixed> $service
     * @return list<int>
     */
    private function observedPorts(array $service): array
    {
        $resolve = $this->imagePorts;

        return $resolve === null ? [] : $resolve((string) ($service['image'] ?? ''));
    }

    /**
     * The named volumes a service mounts, as compose volume strings: only
     * `name:/container/path` where `name` is declared in the file's `volumes:`
     * block. A bind mount (`./x:/y`) is excluded — it would put the account's
     * checkout inside the container.
     *
     * @param array<string, mixed> $service
     * @param list<string> $declared names in the file's `volumes:` block
     * @return list<string>
     */
    private static function namedMountsOf(array $service, array $declared): array
    {
        $mounts = $service['volumes'] ?? null;
        if (!is_array($mounts)) {
            return [];
        }
        $known = array_map('strtolower', $declared);
        $kept = [];
        foreach ($mounts as $mount) {
            if (is_string($mount) && self::isNamedMount($mount, $known)) {
                $kept[] = $mount;
            }
        }

        return $kept;
    }

    /**
     * `dbdata:/var/lib/app`, and only when `dbdata` is declared by the file.
     *
     * @param list<string> $declaredLc lowercase names in the `volumes:` block
     */

    private static function isNamedMount(string $mount, array $declaredLc): bool
    {
        $parts = explode(':', $mount);
        if (count($parts) < 2) {
            return false;
        }
        if (str_contains($parts[0], '/') || !in_array(strtolower(trim($parts[0])), $declaredLc, true)) {
            return false;
        }

        return trim($parts[1]) !== '';
    }

    /**
     * A template service named after the repository, or running an image of
     * that name, is the application in an app-store variant file (Playerr's
     * docker-compose.casaos.yml runs `playerr:latest`), not a backing service.
     * A recognised datastore is never this.
     *
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     */
    private function isNamedAfterTheProject(string $name, array $service, array $observedPorts): bool
    {
        $identity = strtolower(trim((string) $this->projectIdentity, " \t/"));
        $repo = $identity === '' ? '' : (string) array_slice(explode('/', $identity), -1)[0];
        if ($repo === '' || SidecarEngine::isKnownDatastore($name, $service, $observedPorts)) {
            return false;
        }
        $image = strtolower(explode('@', ComposeService::of($service)->image(), 2)[0]);
        $segments = explode('/', $image);
        $imageName = explode(':', (string) end($segments), 2)[0];

        return strtolower($name) === $repo || $imageName === $repo;
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int> $observedPorts
     */
    private function isBacking(string $name, array $service, array $observedPorts): bool
    {
        return SidecarEngine::isBackingService(
            $name,
            $service,
            $observedPorts,
            $this->services,
            $this->projectIdentity
        );
    }
}
