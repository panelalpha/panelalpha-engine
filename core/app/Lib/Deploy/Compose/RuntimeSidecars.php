<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\ServiceRole;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use Symfony\Component\Yaml\Yaml;

/**
 * The services a project's own compose file contributes to a deploy (its runtime datastores, and the env
 * pointing the app at them), without workstation services, host ports or networks; hardened and pinned.
 *
 * @phpstan-type SidecarExtract array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>, app_mounts: list<string>, build_image: ?string, app_aliases: list<string>, dropped_mounts?: list<string>, replaced_mounts?: list<string>, published_secrets: array<string, list<string>>, dropped_proxies?: list<string>, dropped_test_services?: list<string>, dropped_test_matrix?: array{engines: int, databases: list<string>, services: list<string>}|null, hardening_removed?: list<string>, secrets?: array<string, mixed>, configs?: array<string, mixed>, app_secrets?: list<mixed>}
 */
final class RuntimeSidecars
{
    /** @var array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>, app_mounts: list<string>, build_image: ?string, app_aliases: list<string>, published_secrets: array<string, list<string>>} */
    private const EMPTY = [
        'services' => [], 'volumes' => [], 'env' => [], 'app_env' => [],
        'app_mounts' => [], 'build_image' => null, 'app_aliases' => [], 'published_secrets' => [],
    ];

    /** Sail's `laravel.test`: other services routinely depend on it, it never deploys. */
    private const WORKSTATION_APP = 'laravel.test';

    /** Name words of a service that runs the app for a test suite (Zerobyte's `zerobyte-e2e`). */
    private const TEST_VARIANT_WORDS = ['e2e', 'test', 'tests', 'testing', 'ci', 'cypress', 'playwright'];

    /** Name or build-target words of an app variant meant for production (Zerobyte's `zerobyte-prod`). */
    private const PRODUCTION_VARIANT_WORDS = ['prod', 'production', 'release'];

    /** Name or build-target words of an app variant meant for a workstation. */
    private const DEV_VARIANT_WORDS = ['dev', 'development', 'local'];

    /** SQL Server images (mcr.microsoft.com/mssql/server, azure-sql-edge): SQL engines with no sidecar dialect. */
    private const SQL_IMAGES_WITHOUT_DIALECT = '#(^|/)(mssql|mssql-server-linux|azure-sql-edge)([/:@]|$)#';

    /** @var array<string, array<string, mixed>> */
    private array $kept = [];

    /** @var array<string, true> lowercase names of services left out */
    private array $dropped = [];

    /** @var list<string> `service: mount` binds taken off kept services */
    private array $droppedMounts = [];

    /** @var list<string> `service: mount` checkout binds a datastore keeps its data in, now named volumes */
    private array $replacedMounts = [];

    /** @var list<string> `service (image)` reverse proxies left out */
    private array $droppedProxies = [];

    /** @var array<string, string> env harvested from services that were dropped */
    private array $harvested = [];

    /** @var list<mixed> the `secrets:` of the services the build takes the place of */
    private array $appSecrets = [];

    /** @var list<string> lowercase names the repository's compose files give the app built from its root */
    private array $rootBuildNames = [];

    /**
     * Secret-looking literal keys harvested from a dropped service's environment, by service, to warn about.
     *
     * @var array<string, list<string>>
     */
    private array $publishedSecrets = [];

    /**
     * The workstation app service's own env, kept apart from `env` so it ranks below what the strategy generates.
     *
     * @var array<string, string>
     */
    private array $appEnv = [];

    /**
     * Each dropped app variant's env, by service, until {@see chooseAppEnv()} picks the ones the build stands in for.
     *
     * @var array<string, array<string, string>>
     */
    private array $variantEnv = [];

    /** @var list<string> services kept only for a test suite, left out */
    private array $droppedTestServices = [];

    /** @var array{engines: int, databases: list<string>, services: list<string>}|null a test matrix of databases left out */
    private ?array $droppedTestMatrix = null;

    /** @var array<string, list<int>> ports each kept service declared, before hardening strips them */
    private array $ports = [];

    /**
     * Named volumes the file's own app service mounts, taken before it is dropped.
     *
     * @var list<string>
     */
    private array $appMounts = [];

    /**
     * Names the dropped application went by, settled into aliases once every service has been seen.
     *
     * @var list<string>
     */
    private array $appServiceNames = [];

    /**
     * Service names and `container_name`s the generated `app` answers to, so a kept sibling's config resolves.
     *
     * @var list<string>
     */
    private array $appAliases = [];

    /**
     * @param  array<string, mixed>  $services  the file's `services:` block
     * @param  array<string, mixed>  $declaredVolumes  its `volumes:` block
     * @param  bool  $backingServicesOnly  keep datastores only, dropping anything
     *                                     that looks like the application itself
     * @param  callable(string): list<int>|null  $imagePorts  resolves an image
     *                                                        reference to the ports it declares; null when nobody can ask
     * @param  ?string  $projectIdentity  owner/repo being deployed, so its own
     *                                    published image is recognised as the application
     * @param  ?string  $placeholderSeed  per-account secret `${VAR:?}` credentials
     *                                    derive from; null leaves them unset
     * @param  ?SidecarPasswords  $passwords  a datastore password nobody set;
     *                                        null keeps the legacy `app`
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the kept services with
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
        private readonly ?string $projectDir = null,
        private readonly ?string $buildImage = null,
        private readonly array $declaredFiles = []
    ) {}

    /**
     * @param  array<string, list<?string>|string>  $env
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
        $raw = is_file($composePath) && is_readable($composePath) ? AccountHomeRead::contents($composePath) : null;

        return is_string($raw) && $raw !== ''
            ? self::fromYaml($raw, $backingServicesOnly, $imagePorts, $projectIdentity, $accountMemoryMb, $placeholderSeed, $passwords, $env, $accountUser, $projectDir)
            : self::EMPTY;
    }

    /**
     * Customer project files are often unreadable by www-data, so the caller hands the text over. The deploy
     * builds under the tag the file builds its application under; a template has none.
     *
     * @param  array<string, list<?string>|string>  $env
     * @param  list<string>  $rootBuildNames  {@see rootBuildServiceNames()} of the
     *                                        repository's other compose files, for a template
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
        ?string $projectDir = null,
        array $rootBuildNames = []
    ): array {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return self::EMPTY;
        }

        $buildImage = $backingServicesOnly ? null : DeployCompose::builtImageNameFromYaml($raw);

        return (new self(
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
            $projectDir,
            $buildImage,
            ComposeFileReferences::declared($parsed)
        ))->extractedWith($rootBuildNames);
    }

    /**
     * @param  list<string>  $rootBuildNames
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    private function extractedWith(array $rootBuildNames): array
    {
        $this->rootBuildNames = array_values(array_map('strtolower', $rootBuildNames));
        $result = $this->extract();
        $result['build_image'] = $this->buildImage;

        return $result;
    }

    /**
     * Names (and container names) of the services a compose file builds from the repository root: the application.
     *
     * @return list<string>
     */
    public static function rootBuildServiceNames(string $raw): array
    {
        $names = [];
        foreach ((self::parse($raw)['services'] ?? []) as $name => $service) {
            if (! is_array($service) || ! isset($service['build'])) {
                continue;
            }
            $build = $service['build'];
            $context = is_array($build) ? ($build['context'] ?? '.') : $build;
            if (! is_string($context) || ! in_array(rtrim(trim($context), '/'), ['', '.'], true)) {
                continue;
            }
            $names[] = strtolower((string) $name);
            if (is_string($service['container_name'] ?? null) && $service['container_name'] !== '') {
                $names[] = strtolower($service['container_name']);
            }
        }

        return array_values(array_unique($names));
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
        $this->chooseAppEnv();
        $this->dropTestSuite();
        $this->dropAlternativeDatabases();
        $this->clearOfAppService();
        foreach ($this->kept as $name => $service) {
            $this->kept[$name] = ServiceDependencies::withoutDropped($service, $this->dropped);
        }
        $this->collectAppAliases();

        return $this->assemble();
    }

    /**
     * Only the tag the deploy builds exists, so a service running a tag another one builds is dropped.
     *
     * @param  mixed  $service
     */
    private function consider(string $name, $service): void
    {
        if (! is_array($service)) {
            $this->drop($name, []);

            return;
        }
        if ($this->runsAnotherBuildsImage($service)) {
            $this->drop($name, $service);

            return;
        }
        if (HostIngress::isIngressProxy($service)) {
            $this->dropProxy($name, $service);

            return;
        }
        $isApp = ServiceRole::isApplication($name, $service, $this->services, $this->projectIdentity);

        if ($this->isOptIn($service) || $this->isWorkstationOnly($name, $service) || ! $this->hasImage($service)
            || $this->isBuiltHere($name, $service)) {
            $this->dropReplaced($name, $service, $isApp);

            return;
        }

        $this->keepOrDrop($name, $service);
    }

    /**
     * A bundled Traefik is the host's ingress: the account's proxy routes, and its Docker socket is never mounted.
     *
     * @param  array<string, mixed>  $service
     */
    private function dropProxy(string $name, array $service): void
    {
        $this->dropped[strtolower($name)] = true;
        $this->droppedProxies[] = $name.' ('.$service['image'].')';
    }

    /**
     * The file's own app service, replaced by a build of the same repo, leaves its volumes and env to the
     * replacement; a proxy in front names it, so the generated `app` answers to the name (in assemble()).
     *
     * @param  array<string, mixed>  $service
     */
    private function dropReplaced(string $name, array $service, bool $isApp): void
    {
        if ($isApp && ! self::isTestVariant($name)) {
            $this->appMounts = self::namedMountsOf($service, array_keys($this->declaredVolumes));
            $this->noteVariantEnv($name, $service);
        }
        if ($this->isReplacedByOurBuild($name, $service)) {
            $this->appSecrets = ComposeFileReferences::merged($this->appSecrets, ComposeFileReferences::listed($service, 'secrets'));
            $containerName = $service['container_name'] ?? null;
            $this->appServiceNames = ServiceAliases::normalised(array_merge(
                $this->appServiceNames,
                [$name, is_string($containerName) ? $containerName : '']
            ));
        }
        $this->drop($name, $service);
    }

    /**
     * Ports are captured before hardening strips `ports:`: the published port is evidence of what it is.
     *
     * @param  array<string, mixed>  $service
     */
    private function keepOrDrop(string $name, array $service): void
    {
        $observedPorts = $this->observedPorts($service);
        $this->ports[$name] = SidecarEngine::allPorts($service, $observedPorts);

        if ($this->backingServicesOnly
            && (! $this->isBacking($name, $service, $observedPorts) || $this->isNamedAfterTheProject($name, $service, $observedPorts))) {
            $this->drop($name, $service);

            return;
        }

        $this->keep($name, $service);
    }

    /**
     * The app we build takes a workstation app service's place, so it keeps its settings; mailpit and vite do not.
     *
     * @param  array<string, mixed>  $service
     */
    private function drop(string $name, array $service): void
    {
        $this->dropped[strtolower($name)] = true;
        $this->harvested += SidecarCredentials::envFromDroppedAppService($service);
        if (! DevServices::isDevSidecar($name, $service) && ! self::isTestVariant($name)
            && ComposeFileInspector::isWorkstationAppService($service)) {
            $this->noteVariantEnv($name, $service);
        }
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function noteVariantEnv(string $name, array $service): void
    {
        $this->variantEnv[$name] = ($this->variantEnv[$name] ?? [])
            + SidecarCredentials::envFromWorkstationAppService($service, $this->placeholderSeed);
        $this->notePublishedSecrets($name, $service);
    }

    /**
     * The env of the app variants the build stands in for: production wins, else a dev one is skipped when
     * another exists, else all merge. A literal in a variant whose env is not used reaches no deploy.
     */
    private function chooseAppEnv(): void
    {
        $names = array_keys($this->variantEnv);
        $production = array_values(array_filter($names, fn (string $n): bool => $this->variantSays($n, self::PRODUCTION_VARIANT_WORDS)));
        $notDev = array_values(array_filter($names, fn (string $n): bool => ! $this->variantSays($n, self::DEV_VARIANT_WORDS)));
        $chosen = match (true) {
            $production !== [] => $production,
            $notDev !== [] => $notDev,
            default => $names,
        };

        foreach ($chosen as $name) {
            $this->appEnv += $this->variantEnv[$name];
        }
        $this->publishedSecrets = array_intersect_key($this->publishedSecrets, array_flip($chosen));
    }

    /**
     * Whether an app variant's name or `build.target` carries one of $words.
     *
     * @param  list<string>  $words
     */
    private function variantSays(string $name, array $words): bool
    {
        $service = $this->services[$name] ?? null;
        $build = is_array($service) ? ($service['build'] ?? null) : null;
        $target = is_array($build) && is_string($build['target'] ?? null) ? $build['target'] : '';
        $said = preg_split('/[._-]+/', strtolower($name.'-'.$target)) ?: [];

        return array_intersect($said, $words) !== [];
    }

    /**
     * Services kept only for a test suite: named as a test variant, or depended on only by test variants
     * and by each other.
     */
    private function dropTestSuite(): void
    {
        $suite = $this->testSuite();
        foreach (array_keys($this->kept) as $name) {
            if (isset($suite[strtolower((string) $name)])) {
                unset($this->kept[$name], $this->ports[$name]);
                $this->dropped[strtolower((string) $name)] = true;
                $this->droppedTestServices[] = (string) $name;
            }
        }
    }

    /**
     * @return array<string, true> lowercase names
     */
    private function testSuite(): array
    {
        $suite = $this->keptTestVariants();
        do {
            $grew = false;
            foreach (array_keys($this->kept) as $name) {
                $key = strtolower((string) $name);
                if (isset($suite[$key])) {
                    continue;
                }
                $dependants = $this->referencesTo((string) $name);
                if ($dependants === []) {
                    continue;
                }
                if (self::usedOnlyBySuite($dependants, $suite)) {
                    $suite[$key] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        return $suite;
    }

    /**
     * @return array<string, true>
     */
    private function keptTestVariants(): array
    {
        $suite = [];
        foreach (array_keys($this->kept) as $name) {
            if (self::isTestVariant((string) $name)) {
                $suite[strtolower((string) $name)] = true;
            }
        }

        return $suite;
    }

    /**
     * @param  list<string>  $dependants
     * @param  array<string, true>  $suite
     */
    private static function usedOnlyBySuite(array $dependants, array $suite): bool
    {
        foreach ($dependants as $dependant) {
            if (! self::isTestVariant($dependant) && ! isset($suite[strtolower($dependant)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The services in the file that depend on, link to or name $target as a host in their environment.
     *
     * @return list<string>
     */
    private function referencesTo(string $target): array
    {
        $host = '#(^|@|//)'.preg_quote(strtolower($target), '#').'($|[:/?])#';
        $found = [];
        foreach ($this->services as $name => $service) {
            if (! is_array($service) || strcasecmp((string) $name, $target) === 0) {
                continue;
            }
            $refs = array_map('strtolower', ServiceDependencies::namesIn($service));
            $named = in_array(strtolower($target), $refs, true);
            foreach (SidecarCredentials::environmentMap($service['environment'] ?? null) as $value) {
                if ($named || preg_match($host, strtolower((string) $value)) === 1) {
                    $named = true;
                    break;
                }
            }
            if ($named) {
                $found[] = (string) $name;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function notePublishedSecrets(string $name, array $service): void
    {
        $keys = SidecarCredentials::publishedSecretsIn($service);
        if ($keys !== []) {
            $this->publishedSecrets[$name] = $keys;
        }
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function keep(string $name, array $service): void
    {
        unset($service['ports'], $service['networks'], $service['extra_hosts'], $service['profiles']);
        $service = $this->withoutDependencyBinds($name, $service);
        if (ServiceRole::isKnownDatastore($name, $service, $this->ports[$name] ?? [])) {
            $service = $this->withDataOutOfTheCheckout($name, $service);
        }
        if ($this->placeholderSeed !== null && isset($service['environment'])) {
            $service['environment'] = SidecarCredentials::withRequiredSecrets($service['environment'], $this->placeholderSeed);
        }
        $service = SecretFileEnvironment::inlined(
            $service,
            $this->declaredFiles['secrets'] ?? [],
            $this->readProjectFile(...),
            fn (string $secret): string => $this->placeholderSeed === null ? '' : SecretSourceFiles::value($secret, $this->placeholderSeed)
        );

        $this->kept[$name] = ServiceDependencies::withoutDropped(
            $service,
            $this->dropped + [self::WORKSTATION_APP => true]
        );
    }

    /**
     * A file of the checkout by its path relative to the project: null when it does not exist, '' when it
     * cannot be read or there is no project to read it in.
     */
    private function readProjectFile(string $relative): ?string
    {
        if ($this->projectDir === null || $this->projectDir === '') {
            return '';
        }
        $path = rtrim($this->projectDir, '/').'/'.SecretSourceFiles::relative($relative);
        if (! file_exists($path)) {
            return null;
        }
        $contents = is_file($path) ? AccountHomeRead::contents($path) : null;

        return is_string($contents) ? $contents : '';
    }

    /**
     * A bind from a `vendor/` or `node_modules/` path (never a slash-less named volume) is the workstation's
     * installed dependencies, which a checkout never has.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function withoutDependencyBinds(string $name, array $service): array
    {
        if (! is_array($service['volumes'] ?? null)) {
            return $service;
        }
        $kept = [];
        foreach ($service['volumes'] as $volume) {
            $source = trim(is_array($volume) ? (string) ($volume['source'] ?? '') : explode(':', (string) $volume, 2)[0]);
            if (preg_match('#^(\./)*(vendor|node_modules)(/|$)#', $source) === 1 && str_contains($source, '/')) {
                $this->droppedMounts[] = $name.': '.(is_array($volume) ? $source : (string) $volume);

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
     * A workstation datastore keeping its data in the checkout as the laptop's uid runs as its image's
     * own user on a volume instead, since the server refuses to initialise someone else's directory.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function withDataOutOfTheCheckout(string $name, array $service): array
    {
        unset($service['user']);
        if (! is_array($service['volumes'] ?? null)) {
            return $service;
        }
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name)).'-data';
        $count = 0;
        foreach ($service['volumes'] as $i => $volume) {
            if (! is_string($volume)) {
                continue;
            }
            $parts = explode(':', $volume);
            $source = trim($parts[0]);
            $target = trim($parts[1] ?? '');
            $inCheckout = str_starts_with($source, './') && ! str_contains($source, '..');
            if (! $inCheckout || preg_match('#^(/var/lib/|/var/opt/|/bitnami/|/data(/|$))#', $target) !== 1) {
                continue;
            }
            $parts[0] = $base.($count++ === 0 ? '' : '-'.$count);
            $service['volumes'][$i] = implode(':', $parts);
            $this->replacedMounts[] = $name.': '.$volume;
        }

        return $service;
    }

    /**
     * Of a choice of SQL databases, only the one the app's environment names stays; with none named all stay,
     * unless three or more products make it a test matrix ({@see dropTestMatrix()}).
     */
    private function dropAlternativeDatabases(): void
    {
        [$engines, $products] = $this->keptSqlEngines();
        if (count(array_unique($engines)) < 2) {
            return;
        }

        $named = array_values(array_unique(array_intersect_key($engines, $this->namedByDroppedServices(array_keys($engines)))));
        $distinct = count(array_unique($products));
        if ($named === [] && $distinct >= 3) {
            $this->dropTestMatrix();

            return;
        }
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
     * MariaDB is configured as MySQL, but a file running both is testing against both, so products count apart.
     *
     * @return array{0: array<string, string>, 1: list<string>} name => engine, and the image products
     */
    private function keptSqlEngines(): array
    {
        $engines = [];
        $products = [];
        foreach ($this->kept as $name => $service) {
            $engine = SidecarEngine::resolve((string) $name, $service, $this->ports[$name] ?? []);
            if ($engine !== null && SidecarEngine::dialect($engine)['driver'] !== null) {
                $engines[(string) $name] = $engine;
                $products[] = ComposeService::of($service)->imageFamily() ?: $engine;
            }
        }

        return [$engines, $products];
    }

    /**
     * Every kept service the dropped app services' environment does not name as a host goes (`links:` are no
     * evidence there); counted over the dropped services, so the log never contradicts its list.
     */
    private function dropTestMatrix(): void
    {
        $named = $this->namedByDroppedServices(array_map('strval', array_keys($this->kept)));
        $databases = [];
        $others = [];
        foreach (array_keys($this->kept) as $name) {
            $name = (string) $name;
            if (isset($named[$name])) {
                continue;
            }
            if ($this->isSqlDatabase($name, $this->kept[$name])) {
                $databases[] = $name;
            } else {
                $others[] = $name;
            }
            unset($this->kept[$name], $this->ports[$name]);
            $this->dropped[strtolower($name)] = true;
        }
        $this->droppedTestMatrix = ['engines' => count($databases), 'databases' => $databases, 'services' => array_merge($databases, $others)];
    }

    /**
     * An SQL engine we speak, or SQL Server, which has no dialect but is one.
     *
     * @param  array<string, mixed>  $service
     */
    private function isSqlDatabase(string $name, array $service): bool
    {
        $engine = SidecarEngine::resolve($name, $service, $this->ports[$name] ?? []);
        if ($engine !== null && SidecarEngine::dialect($engine)['driver'] !== null) {
            return true;
        }

        return preg_match(self::SQL_IMAGES_WITHOUT_DIALECT, strtolower(ComposeService::of($service)->image())) === 1;
    }

    /**
     * Which of $candidates a dropped service's environment points at as a host (`DB_HOST=postgresql`).
     *
     * @param  list<string>  $candidates
     * @return array<string, true>
     */
    private function namedByDroppedServices(array $candidates): array
    {
        $values = [];
        foreach ($this->services as $name => $service) {
            if (isset($this->kept[$name]) || ! is_array($service)) {
                continue;
            }
            $values = array_merge($values, array_values(SidecarCredentials::environmentMap($service['environment'] ?? null)));
        }

        $named = [];
        foreach ($candidates as $candidate) {
            $host = '#(^|@|//)'.preg_quote(strtolower($candidate), '#').'($|[:/?])#';
            foreach ($values as $value) {
                if (preg_match($host, strtolower($value)) === 1) {
                    $named[$candidate] = true;
                    break;
                }
            }
        }

        return $named;
    }

    /** A kept sidecar named `app` would clash with the generated app, so it moves to a free name with its references. */
    private function clearOfAppService(): void
    {
        foreach (array_keys($this->kept) as $name) {
            $name = (string) $name;
            if (strcasecmp($name, GeneratedCompose::APP_SERVICE) !== 0
                || ServiceRole::isApplication($name, $this->kept[$name], $this->services, $this->projectIdentity)) {
                continue;
            }
            $to = $this->freeName($name.'-sidecar');
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
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }

    /** A build of the app set up for a test run, whose settings must not reach the deployed app. */
    private static function isTestVariant(string $name): bool
    {
        $words = preg_split('/[._-]+/', strtolower($name)) ?: [];

        return array_intersect($words, self::TEST_VARIANT_WORDS) !== [];
    }

    /**
     * `build:` means the image comes from this repo, not a sidecar image; a catalogued datastore is still pulled.
     *
     * @param  array<string, mixed>  $service
     */
    private function isBuiltHere(string $name, array $service): bool
    {
        return isset($service['build']) && ! ServiceRole::isKnownDatastore($name, $service);
    }

    /**
     * Whether this deploy's build takes the service's place, so the generated `app` answers to its name;
     * a dev sidecar that builds (vite) is not replaced by the app.
     *
     * @param  array<string, mixed>  $service
     */
    private function isReplacedByOurBuild(string $name, array $service): bool
    {
        if (DevServices::isDevSidecar($name, $service)) {
            return false;
        }

        return (isset($service['build']) || self::isUnresolvedImage($service))
            && ! ServiceRole::isKnownDatastore($name, $service);
    }

    /**
     * Settles the names the generated app answers to; an alias a kept service already answers to would
     * resolve to two containers, so the kept names win.
     */
    private function collectAppAliases(): void
    {
        if ($this->kept === [] || $this->appServiceNames === []) {
            return;
        }

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
            static fn (string $alias): bool => ! isset($taken[strtolower($alias)])
        ));
    }

    /**
     * keep() already stripped `ports`, so the one-shot rules read the original file to see them.
     *
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>, app_env: array<string, string>}
     */
    private function assemble(): array
    {
        $volumes = NamedVolumes::usedBy($this->kept, $this->declaredVolumes);
        $files = ComposeFileReferences::usedBy($this->kept, $this->appSecrets, $this->declaredFiles);
        ['compose' => $hardened, 'removed' => $removed] = ComposeHarden::applyReporting(
            ['services' => $this->kept, 'volumes' => $volumes] + $files,
            $this->accountMemoryMb,
            $this->services,
            $this->env,
            $this->accountUser,
            $this->projectDir
        );
        [$services, $env] = $this->withCredentialsPinned(is_array($hardened['services'] ?? null) ? $hardened['services'] : []);

        return [
            'services' => $services,
            'volumes' => is_array($hardened['volumes'] ?? null) ? $hardened['volumes'] : $volumes,
            'env' => $env + $this->harvested,
            'app_env' => $this->appEnv,
            'app_mounts' => $this->appMounts,
            'app_aliases' => $this->appAliases,
            'dropped_mounts' => $this->ofKeptServices($this->droppedMounts),
            'published_secrets' => $this->publishedSecrets,
            'replaced_mounts' => $this->ofKeptServices($this->replacedMounts),
            'dropped_proxies' => $this->droppedProxies,
            'dropped_test_services' => $this->droppedTestServices,
            'dropped_test_matrix' => $this->droppedTestMatrix,
            'hardening_removed' => $removed,
        ] + $this->declaredFilesOf($hardened);
    }

    /**
     * The `secrets:` and `configs:` the kept services and the app name, past the hardener, and the app's own
     * secrets among them: left behind, Compose refused the file with "refers to undefined secret".
     *
     * @param  array<string, mixed>  $hardened
     * @return array{secrets?: array<string, mixed>, configs?: array<string, mixed>, app_secrets?: list<mixed>}
     */
    private function declaredFilesOf(array $hardened): array
    {
        $out = [];
        foreach (ComposeFileReferences::SECTIONS as $section) {
            if (is_array($hardened[$section] ?? null) && $hardened[$section] !== []) {
                $out[$section] = $hardened[$section];
            }
        }
        $appSecrets = ComposeFileReferences::onlyDeclared($this->appSecrets, $out['secrets'] ?? []);
        if ($appSecrets !== []) {
            $out['app_secrets'] = $appSecrets;
        }

        return $out;
    }

    /**
     * A bare secret nothing sets (`--requirepass ${REDIS_PASSWORD}`) gets one value for sidecar and app;
     * the env union keeps what is set, so of two candidates the first declared wins.
     *
     * @param  array<array-key, mixed>  $services
     * @return array{0: array<array-key, mixed>, 1: array<string, string>}
     */
    private function withCredentialsPinned(array $services): array
    {
        $env = [];
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $ports = $this->ports[$name] ?? [];
            $services[$name] = SidecarCredentials::pinSidecarCredentials((string) $name, $service, $ports, $this->passwords);
            $generated = [];
            if ($this->placeholderSeed !== null) {
                [$services[$name], $generated] = SidecarCredentials::withBareSecretsFilled(
                    $services[$name],
                    $this->placeholderSeed,
                    $this->env
                );
            }
            $env += $generated + SidecarCredentials::envForSidecar((string) $name, $services[$name], $ports);
        }

        return [$services, $env];
    }

    /**
     * `service: mount` lines without the services dropped after their mounts were rewritten: those run nothing.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function ofKeptServices(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            fn (string $line): bool => ! isset($this->dropped[strtolower(explode(': ', $line, 2)[0])])
        ));
    }

    /**
     * A `profiles:` gate is opt-in: `docker compose up` without `--profile` never starts the service.
     *
     * @param  array<string, mixed>  $service
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
     * A stock image running the checkout from a bind (an asset watcher) is workstation tooling too; a job on
     * the image this file builds (a migration) stays.
     *
     * @param  array<string, mixed>  $service
     */
    private function isWorkstationOnly(string $name, array $service): bool
    {
        return DevServices::isDevSidecar($name, $service)
            || ComposeFileInspector::isWorkstationAppService($service)
            || (ComposeFileInspector::mountsWholeProjectRoot($service)
                && ! ServiceRole::isKnownDatastore($name, $service)
                && ! $this->runsAnImageThisFileBuilds($service));
    }

    /**
     * @param  array<string, mixed>  $service
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
     * A service without `build:` on a tag another service of this file builds, when this deploy builds
     * under a different one. Templates are left alone.
     *
     * @param  array<string, mixed>  $service
     */
    private function runsAnotherBuildsImage(array $service): bool
    {
        if ($this->backingServicesOnly || isset($service['build'])) {
            return false;
        }
        $image = ImageTransfer::normalizeImageRef($service['image'] ?? null);
        if ($image === null || strcasecmp($image, (string) ImageTransfer::normalizeImageRef($this->buildImage)) === 0) {
            return false;
        }

        return $this->runsAnImageThisFileBuilds($service);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function hasImage(array $service): bool
    {
        return trim((string) ($service['image'] ?? '')) !== '' && ! self::isUnresolvedImage($service);
    }

    /**
     * `image: ${IMAGE}` with nothing to set it (a `${VAR:?msg}` has no fallback either), which compose rejects
     * as "neither an image nor a build context".
     *
     * @param  array<string, mixed>  $service
     */
    private static function isUnresolvedImage(array $service): bool
    {
        $image = trim((string) ($service['image'] ?? ''));
        if ($image === '' || ! str_contains($image, '$')) {
            return false;
        }
        $image = (string) preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*:?\?[^}]*\}/', '', $image);

        return trim(EnvVarDefault::resolve($image)) === '';
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<int>
     */
    private function observedPorts(array $service): array
    {
        $resolve = $this->imagePorts;

        return $resolve === null ? [] : $resolve((string) ($service['image'] ?? ''));
    }

    /**
     * The named volumes a service mounts (`name:/path`, `name` declared in the file's `volumes:`), never a bind,
     * which would put the account's checkout inside the container.
     *
     * @param  array<string, mixed>  $service
     * @param  list<string>  $declared  names in the file's `volumes:` block
     * @return list<string>
     */
    private static function namedMountsOf(array $service, array $declared): array
    {
        $mounts = $service['volumes'] ?? null;
        if (! is_array($mounts)) {
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
     * @param  list<string>  $declaredLc  lowercase names in the `volumes:` block
     */
    private static function isNamedMount(string $mount, array $declaredLc): bool
    {
        $parts = explode(':', $mount);
        if (count($parts) < 2) {
            return false;
        }
        if (str_contains($parts[0], '/') || ! in_array(strtolower(trim($parts[0])), $declaredLc, true)) {
            return false;
        }

        return trim($parts[1]) !== '';
    }

    /**
     * A template service named after the repository (or its compose's root build), or running an image of that
     * name, is the application in an app-store variant file; a recognised datastore never is.
     *
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
     */
    private function isNamedAfterTheProject(string $name, array $service, array $observedPorts): bool
    {
        $identity = strtolower(trim((string) $this->projectIdentity, " \t/"));
        $names = $this->rootBuildNames;
        if ($identity !== '') {
            $names[] = (string) array_slice(explode('/', $identity), -1)[0];
        }
        if ($names === [] || SidecarEngine::isKnownDatastore($name, $service, $observedPorts)) {
            return false;
        }
        $image = strtolower(explode('@', ComposeService::of($service)->image(), 2)[0]);
        $segments = explode('/', $image);
        $imageName = explode(':', (string) end($segments), 2)[0];
        $containerName = is_string($service['container_name'] ?? null) ? strtolower($service['container_name']) : '';

        return array_intersect([strtolower($name), $imageName, $containerName], $names) !== [];
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  list<int>  $observedPorts
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
