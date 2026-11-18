<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeBuilds;
use App\Lib\Deploy\Compose\ComposeExtends;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeInclude;
use App\Lib\Deploy\Compose\ComposeOverride;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NamedVolumes;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\Compose\PrimaryPortBinding;
use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\UnpublishedAppPort;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\LxcfsProc;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\Source\GitRepository;

/**
 * The project's own compose file, made fit to host with as few changes as it can and written to the run file
 * (ADR-0001), so the customer's own file stays byte-identical to their repository.
 */
class UserComposeStrategy
{
    /** The client-override copy for an override the deploy refused: it layers nothing. */
    private const NOT_LAYERED_OVERRIDE = "# The repository's docker-compose.override.yml is a development setup and is not layered.\nservices: {}\n";

    private DindProject $dind;

    /** The repository's override as it was before the app config ran; null for none. */
    private ?string $repositoryOverride = null;

    private bool $repositoryOverrideNoted = false;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Called before the app config writes files or runs its prepare hook, so a deploy with no git history
     * (an archive) can still tell the repository's override from one the recipe wrote.
     */
    public function noteRepositoryOverride(string $projectDir): void
    {
        $fs = $this->dind->system()->filesystem();
        $source = rtrim($projectDir, '/').'/'.Paths::CLIENT_OVERRIDE_FILENAME;
        $this->repositoryOverride = $fs->fileExists($source) ? $fs->fileGetContents($source) : null;
        $this->repositoryOverrideNoted = true;
    }

    /**
     * The platform's prepare runs only while there is no `.env` yet, since one means an earlier deploy ran it.
     *
     * @param  array{compose_path: ?string, ...}  $decision
     */
    public function apply(
        array $decision,
        ?AppConfig $appConfig,
        string $projectDir,
        ?string $chown,
        string $sourceLabel
    ): void {
        $composePath = is_string($decision['compose_path'] ?? null) && $decision['compose_path'] !== ''
            ? $decision['compose_path']
            : $this->dind->userAppExistingComposeFilePath();

        if ($composePath === null) {
            $this->dind->shell()->logger()?->warn('No compose file to run, so the placeholder page is served');
            $this->dind->strategy()->railpack()->applyAsUnknown($projectDir, $chown, $sourceLabel);

            return;
        }

        $this->normalize($composePath, $projectDir, $chown);
        if (! $this->dind->system()->filesystem()->fileExists("{$projectDir}/.env")) {
            $prepare = $this->dind->strategy()->prepare();
            $prepare->run($projectDir, $prepare->manifestFor($decision, $appConfig), $appConfig);
        }
    }

    /**
     * Re-runs {@see normalize()} on the project's current compose file, so container actions pick up an edit
     * before `up`/`pull`; a no-op without one.
     */
    public function refreshRunFile(string $projectDir, ?string $chown): void
    {
        $source = $this->dind->userAppExistingComposeFilePath();
        if ($source === null) {
            return;
        }

        $this->normalize($source, $projectDir, $chown);
    }

    /**
     * Points missing compose Dockerfiles at the root one, applies the hosting hardening and writes the result
     * to the run file (ADR-0001); the project's own file is only read.
     */
    private function normalize(string $composePath, string $projectDir, ?string $chown): void
    {
        $logger = $this->dind->shell()->logger();
        $system = $this->dind->system();
        $runPath = $this->dind->userAppComposeFilePath();
        $env = $this->dind->environment()->forInterpolation();
        $replaced = basename($composePath) === EngineArtifacts::APP_CONFIG_COMPOSE;
        $missing = ComposeFileInspector::missingComposeDockerfileRefs($composePath, $projectDir);
        $raw = $system->filesystem()->fileGetContents($composePath);
        [$parsed, $included] = $this->readCompose($raw, $composePath, $projectDir, $system, $logger);
        if (! isset($parsed['services']) || ! is_array($parsed['services'])) {
            $this->assertNoMissingDockerfile($missing);
            $this->writeClientOverride($projectDir, $chown, $logger, $replaced, $env, []);
            $system->filesystem()->writeFileReplacingPath(
                $runPath,
                $included === [] ? $raw : ComposeYaml::dump($parsed, $raw, 6, 2, ...$included),
                $chown,
                EngineArtifacts::RUN_COMPOSE_MODE
            );

            return;
        }

        $parsed = $this->withFallbackDockerfiles($parsed, $projectDir, $logger);
        $parsed = $this->withBuildCaches($parsed, $projectDir);
        $parsed = $this->hostingReady($parsed, $composePath, $projectDir, $chown, $logger, $replaced, $env);
        $parsed = $this->withAppEnvFile($parsed);
        $parsed = $this->withEnvOverrides($parsed, $system, $projectDir);
        $system->filesystem()->writeFileReplacingPath($runPath, ComposeYaml::dump($parsed, $raw, 6, 2, ...$included), $chown, EngineArtifacts::RUN_COMPOSE_MODE);
        $this->dind->strategy()->installRailsHostInitializer($projectDir, $chown);
        $logger?->info('Hardened compose for hosting (resource limits, restart policy, isolation)');
    }

    /**
     * The file through ComposeYaml, rebased to the root, with its includes flattened and `extends:` merged.
     *
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     */
    private function readCompose(string $raw, string $composePath, string $projectDir, System $system, ?DeployLogger $logger): array
    {
        $parsed = $this->parse($raw);
        $parsed = $this->rebasedToRoot($parsed, $composePath, $projectDir, $logger);
        $included = [];
        if (array_key_exists('include', $parsed)) {
            ['compose' => $parsed, 'sources' => $included] = $this->flattenIncludes($parsed, $projectDir, $logger);
        }

        return [ComposeYaml::withoutMergeTags($this->withExtendsResolved($parsed, $system, $projectDir)), $included];
    }

    /**
     * Through ComposeYaml, not Yaml::parse, so a construct Symfony rejects but Docker accepts is rescued
     * rather than failing the account; null is a file that is genuinely unreadable. Merge tags stay for
     * the `extends:` merge.
     *
     * @return array<string, mixed>
     */
    private function parse(string $raw): array
    {
        $parsed = ComposeYaml::parseKeepingMergeTags($raw);
        if ($parsed === null) {
            throw new \InvalidArgumentException(
                'The compose file in this project could not be read as YAML.'
            );
        }

        return $parsed;
    }

    /**
     * The run file sits at the root, so a file kept under docker/ has its relative paths rewritten.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function rebasedToRoot(array $parsed, string $composePath, string $projectDir, ?DeployLogger $logger): array
    {
        $nested = NestedCompose::relativeDir($composePath, $projectDir);
        if ($nested !== null) {
            $parsed = NestedCompose::rebase($parsed, $nested);
            $logger?->info("Running {$nested}/".basename($composePath).' from the project root, its relative paths rewritten to match');
        }

        return $parsed;
    }

    /**
     * Included services are hardened and scanned like the file's own; left as `include:` they ran as written.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function flattenIncludes(array $parsed, string $projectDir, ?DeployLogger $logger): array
    {
        $read = fn (string $relative): ?string => $this->dind->projectTree()->read(rtrim($projectDir, '/').'/'.$relative);
        $flattened = ComposeInclude::flatten($parsed, $read);
        $logger?->info('Merged the files this compose file includes into the run file');

        return $flattened;
    }

    /**
     * `extends:` is merged in before hardening; left as written, Compose merged the extended service's escapes
     * back in at run time, past the hardener.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withExtendsResolved(array $parsed, System $system, string $projectDir): array
    {
        if (isset($parsed['services']) && is_array($parsed['services'])) {
            $fs = $system->filesystem();
            $read = function (string $relative) use ($fs, $projectDir): ?string {
                $path = rtrim($projectDir, '/').'/'.$relative;

                return $fs->fileExists($path) ? $fs->fileGetContents($path) : null;
            };
            $parsed = ComposeExtends::resolve($parsed, $read);
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $missing
     */
    private function assertNoMissingDockerfile(array $missing): void
    {
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Compose file builds from '.$missing[0].' which does not exist.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withFallbackDockerfiles(array $parsed, string $projectDir, ?DeployLogger $logger): array
    {
        $fallbackDockerfile = is_file($projectDir.'/Dockerfile');
        foreach ($parsed['services'] as &$service) {
            $dockerfile = $this->missingDockerfile($service, $projectDir, $fallbackDockerfile);
            if ($dockerfile === null) {
                continue;
            }
            $service['build']['dockerfile'] = 'Dockerfile';
            $logger?->info("Compose dockerfile {$dockerfile} is missing; using Dockerfile");
        }
        unset($service);

        return $parsed;
    }

    /**
     * The missing Dockerfile a root-context build falls back from to the root one; null when nothing is missing.
     */
    private function missingDockerfile(mixed $service, string $projectDir, bool $fallbackDockerfile): ?string
    {
        if (! is_array($service) || ! isset($service['build'])) {
            return null;
        }
        $build = $service['build'];
        if (! is_string($build) && ! is_array($build)) {
            return null;
        }
        $abs = ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build);
        if ($abs === null || is_file($abs)) {
            return null;
        }
        $dockerfile = is_array($build)
            ? (string) ($build['dockerfile'] ?? 'Dockerfile')
            : 'Dockerfile';
        $canFallback = $fallbackDockerfile
            && is_array($build)
            && ComposeFileInspector::composeBuildContextIsProjectRoot($build);
        if (! $canFallback) {
            throw new \InvalidArgumentException(
                'Compose file builds from '.$dockerfile.' which does not exist.'
            );
        }

        return $dockerfile;
    }

    /**
     * The host ingress dropped, the client override layered, the ports published, the volumes named,
     * the hardening applied and the placeholders filled.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, list<?string>>  $env
     * @return array<string, mixed>
     */
    private function hostingReady(
        array $parsed,
        string $composePath,
        string $projectDir,
        ?string $chown,
        ?DeployLogger $logger,
        bool $replaced,
        array $env
    ): array {
        $services = $parsed['services'];
        $parsed = $this->withoutHostIngress($parsed, $logger);
        $this->writeClientOverride($projectDir, $chown, $logger, $replaced, $env, $this->droppedServices($services, $parsed));
        $parsed = $this->withPublishedPorts($parsed, $composePath, $projectDir, $logger);
        $this->reportServiceAdjustments($parsed, $logger);
        $parsed = $this->withNamedVolumesForUnsetSources($parsed, $env, $logger);

        return $this->fillPlaceholders($this->hardened($parsed, $env, $logger), $logger);
    }

    /**
     * Before port detection reads the file, so a bundled Traefik's :80 is never taken for the app's port.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withoutHostIngress(array $parsed, ?DeployLogger $logger): array
    {
        $ingress = ComposeHarden::withoutHostIngress($parsed);
        $parsed = $ingress['compose'];
        foreach ($ingress['dropped'] as $line) {
            $logger?->info($line);
        }

        return $parsed;
    }

    /**
     * The services the drops removed, which an override naming them would bring back as fragments with no image.
     *
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function droppedServices(mixed $services, array $parsed): array
    {
        return array_values(array_diff(array_map('strval', array_keys($services ?? [])), array_map('strval', array_keys($parsed['services']))));
    }

    /**
     * The DinD proxy routes the domain to the detected primary port, and a service that only expose:s it binds
     * nothing there, so it is published explicitly.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withPublishedPorts(array $parsed, string $composePath, string $projectDir, ?DeployLogger $logger): array
    {
        $parsed = $this->withUnpublishedAppPort($parsed, $composePath, $projectDir, $logger);
        $binding = ComposeHarden::withPublishedPrimaryPort($parsed);
        $parsed = $binding['compose'];
        if ($binding['published'] !== null) {
            $logger?->info("Published detected primary port {$binding['published']} so the domain reaches this app");
        }

        return ProjectPublicRouting::publishDeclaredPorts($this->dind->userModel(), $parsed, $logger);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function reportServiceAdjustments(array $parsed, ?DeployLogger $logger): void
    {
        foreach (ComposeHarden::oneShotServices($parsed) as $name) {
            $logger?->info("Service {$name} runs once and exits; not restarting it");
        }

        foreach ($parsed['services'] as $name => $service) {
            if (is_array($service) && ServiceHardener::requestsMemlockUlimit($service)) {
                $logger?->info("Removed ulimits.memlock from service {$name}: an account cannot raise its locked-memory limit");
            }
            if (is_array($service) && ServiceHardener::needsStartPeriodUnderCap($service)) {
                $logger?->info("Gave {$name}'s healthcheck a 60s start period: its CPU is capped and the check allows no slow start");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, list<?string>>  $env
     * @return array<string, mixed>
     */
    private function withNamedVolumesForUnsetSources(array $parsed, array $env, ?DeployLogger $logger): array
    {
        ['compose' => $parsed, 'replaced' => $unset] = NamedVolumes::forUnsetSources($parsed, $env);
        foreach ($unset as $what) {
            $logger?->info("A mount source variable is not set, so it is a named volume: {$what}");
        }

        return $parsed;
    }

    /**
     * Under the account's own memory ceiling, so a project given more memory gets it; none keeps the defaults.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, list<?string>>  $env
     * @return array<string, mixed>
     */
    private function hardened(array $parsed, array $env, ?DeployLogger $logger): array
    {
        $imageEnv = [];
        $hardened = ComposeHarden::applyReporting(
            $parsed,
            $this->dind->userModel()->effectiveMemoryLimit(),
            null,
            $env,
            $this->dind->userModel()->username,
            $this->dind->userAppDirPath(),
            LxcfsProc::inAccount($this->dind),
            function (string $image) use (&$imageEnv): array {
                return $imageEnv[$image] ??= $this->dind->innerDocker()->imageEnvironment($image);
            }
        );
        $parsed = $hardened['compose'];
        foreach ($hardened['removed'] as $what) {
            $logger?->warn(ComposeHarden::REMOVED_SOURCE.": {$what}");
        }

        return $parsed;
    }

    /**
     * The account's environment reaches a compose file the engine did not generate through `.env`, which a
     * service reads only when its `env_file:` names it: the service that publishes the app's port loads it.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withAppEnvFile(array $parsed): array
    {
        [$parsed] = ComposeEnvFiles::appendTo($parsed, PrimaryPortBinding::appServices($parsed), '.env');

        return $parsed;
    }

    /**
     * A tracked .env's overrides (ADR-0001 D3), kept attached when the run file is regenerated without a deploy.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withEnvOverrides(array $parsed, System $system, string $projectDir): array
    {
        if ($system->filesystem()->fileExists($projectDir.'/'.EngineArtifacts::ENV_OVERRIDES)) {
            [$parsed] = ComposeEnvFiles::attach($parsed, EngineArtifacts::ENV_OVERRIDES);
        }

        return $parsed;
    }

    /**
     * A compose file publishing no port has the port it names some other way exposed ({@see UnpublishedAppPort})
     * rather than being routed to 8080, and a remaining guess is said to be one.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withUnpublishedAppPort(array $parsed, string $composePath, string $projectDir, ?DeployLogger $logger): array
    {
        if (isset(ComposePortScan::ofParsed($parsed)['primary'])) {
            return $parsed;
        }
        if ($this->hardenerPublishesWebPort($parsed)) {
            return $parsed;
        }

        $others = $this->otherComposeFiles($composePath, $projectDir);
        $found = UnpublishedAppPort::apply(
            $parsed,
            fn (string $image): array => $this->dind->innerDocker()->declaredImagePorts($image),
            $others
        );
        if ($found === null) {
            $this->warnAboutGuessedPort($logger);

            return $parsed;
        }
        $logger?->info("No service publishes a port; {$found['service']} listens on {$found['port']}, from {$found['source']}");

        return $found['compose'];
    }

    /**
     * A host-network service's PORT is published by the hardener later on.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function hardenerPublishesWebPort(array $parsed): bool
    {
        foreach ($parsed['services'] ?? [] as $service) {
            if (is_array($service) && ServiceHardener::publishesWebPort($service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function otherComposeFiles(string $composePath, string $projectDir): array
    {
        $others = [];
        foreach (['docker-compose.*.yml', 'docker-compose.*.yaml', 'compose.*.yml', 'compose.*.yaml'] as $pattern) {
            foreach (glob(rtrim($projectDir, '/').'/'.$pattern) ?: [] as $path) {
                if (basename($path) === basename($composePath) || Paths::isEngineComposeFile($path)) {
                    continue;
                }
                $raw = $this->dind->projectTree()->read($path);
                if ($raw !== null) {
                    $others[basename($path)] = $raw;
                }
            }
        }

        return $others;
    }

    /** With a recipe `port:` the domain is routed there, so nothing is guessed. */
    private function warnAboutGuessedPort(?DeployLogger $logger): void
    {
        if (Networking::recipeComposePort($this->dind->userModel()) === null) {
            $logger?->warn('No service publishes a port and none names one, so the domain is routed to 8080, which is a guess. Publish the port the application listens on in the compose file.');
        }
    }

    /**
     * The `<Dockerfile>.dockerignore` a repository Dockerfile gets, for every `build:` in the run file,
     * so `.git` and the engine's files stay out of an image that may serve its context.
     */
    public function keepEngineFilesOutOfContext(string $projectDir, ?string $chown): void
    {
        $raw = $this->dind->projectTree()->read($this->dind->userAppComposeFilePath());
        $parsed = $raw === null ? null : ComposeYaml::parse($raw);
        if (! is_array($parsed['services'] ?? null)) {
            return;
        }

        $projectDir = rtrim($projectDir, '/');
        $written = [];
        foreach ($parsed['services'] as $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            if ((! is_string($build) && ! is_array($build))
                || ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build) === null
            ) {
                continue;
            }
            $context = trim((string) (is_string($build) ? $build : ($build['context'] ?? '.')), '/');
            $context = ($context === '' || $context === '.') ? '' : (string) preg_replace('#^(\./)+#', '', $context);
            $dockerfile = is_array($build) && is_string($build['dockerfile'] ?? null) && $build['dockerfile'] !== ''
                ? $build['dockerfile']
                : 'Dockerfile';
            $contextDir = $context === '' ? $projectDir : $projectDir.'/'.$context;
            if (str_starts_with($dockerfile, '/') || isset($written[$contextDir."\0".$dockerfile])) {
                continue;
            }
            $written[$contextDir."\0".$dockerfile] = true;
            $this->dind->strategy()->contextIgnore()->write($contextDir, $dockerfile, false, $chown);
        }
    }

    /**
     * Each service that builds from the checkout imports the host-built
     * cache of that build when the cache registry has one ({@see ComposeBuilds}).
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function withBuildCaches(array $parsed, string $projectDir): array
    {
        $cache = new ProjectBuildCache($this->dind);
        foreach (ComposeBuilds::of($parsed) as $name => $build) {
            $from = $cache->cacheFrom($projectDir, ComposeBuilds::identity($build['context'], $build['dockerfile'], $build['target']));
            if ($from !== []) {
                $parsed['services'][$name]['build'] = ComposeBuilds::withCacheFrom($parsed['services'][$name]['build'], $from);
            }
        }

        return $parsed;
    }

    /**
     * The repository's `docker-compose.override.yml`, copied with its escapes removed to a name the engine owns
     * ({@see Paths::composeFiles()} layers the copy); a workstation override is refused like a workstation run file.
     *
     * @param  array<string, list<?string>>  $env
     * @param  list<string>  $droppedServices  services the run file no longer has
     */
    private function writeClientOverride(string $projectDir, ?string $chown, ?DeployLogger $logger, bool $replaced, array $env, array $droppedServices): void
    {
        $fs = $this->dind->system()->filesystem();
        $source = rtrim($projectDir, '/').'/'.Paths::CLIENT_OVERRIDE_FILENAME;
        $copy = rtrim($projectDir, '/').'/'.EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $contents = $fs->fileExists($source) ? $fs->fileGetContents($source) : null;
        $stale = $this->isStaleOverride($projectDir, $contents, $replaced);
        $workstation = $contents === null || $stale ? null : ComposeFileInspector::localDevComposeReasonYaml($contents);
        if ($workstation !== null) {
            $this->writeNotLayeredOverride($fs, $copy, $chown, $logger, $workstation);

            return;
        }
        if ($contents === null || $stale) {
            $this->removeOverrideCopy($fs, $copy, $stale, $logger);

            return;
        }

        $this->writeHardenedOverride($fs, $projectDir, $copy, $contents, $chown, $logger, $env, $droppedServices);
    }

    /**
     * The repository's override belongs to the compose file an app config replaced; one a recipe wrote
     * (prepare hook, files/) is still layered.
     */
    private function isStaleOverride(string $projectDir, ?string $contents, bool $replaced): bool
    {
        $committed = $contents !== null && $replaced
            ? $this->committedVersion($projectDir, Paths::CLIENT_OVERRIDE_FILENAME)
                ?? ($this->repositoryOverrideNoted ? $this->repositoryOverride : null)
            : null;

        return $committed !== null && rtrim($committed) === rtrim((string) $contents);
    }

    /** An empty copy, not none: without one Paths::composeFiles() layers the raw file. */
    private function writeNotLayeredOverride(Filesystem $fs, string $copy, ?string $chown, ?DeployLogger $logger, string $workstation): void
    {
        $logger?->info('Not layering '.Paths::CLIENT_OVERRIDE_FILENAME.": {$workstation}, which is a development setup");
        $fs->writeFileReplacingPath($copy, self::NOT_LAYERED_OVERRIDE, $chown, '644');
    }

    private function removeOverrideCopy(Filesystem $fs, string $copy, bool $stale, ?DeployLogger $logger): void
    {
        if ($fs->fileExists($copy)) {
            $this->dind->system()->exec(Privileged::accountFiles(...$this->dind->userModel()->linuxIds())->removeIfExists($copy));
        }
        if ($stale) {
            $logger?->info(
                'Not layering the repository\'s '.Paths::CLIENT_OVERRIDE_FILENAME
                .': it was written for the compose file the app config replaces'
            );
        }
    }

    /**
     * @param  array<string, list<?string>>  $env
     * @param  list<string>  $droppedServices
     */
    private function writeHardenedOverride(
        Filesystem $fs,
        string $projectDir,
        string $copy,
        string $contents,
        ?string $chown,
        ?DeployLogger $logger,
        array $env,
        array $droppedServices
    ): void {
        $read = fn (string $relative): ?string => $this->dind->projectTree()->read(rtrim($projectDir, '/').'/'.$relative);
        ['yaml' => $contents, 'dropped' => $gone] = ComposeOverride::withoutServices($contents, $droppedServices);
        $hardened = ComposeOverride::harden($contents, $read, $env, $this->dind->userModel()->username, $this->dind->userAppDirPath());
        if ($hardened['yaml'] === null) {
            throw new \InvalidArgumentException(
                'The compose override in this project ('.Paths::CLIENT_OVERRIDE_FILENAME.') could not be read as YAML.'
            );
        }
        foreach ($hardened['removed'] as $what) {
            $logger?->warn('Removed from '.Paths::CLIENT_OVERRIDE_FILENAME.", not allowed in hosting: {$what}");
        }
        foreach ($gone as $name) {
            $logger?->info('Dropped service '.$name.' from '.Paths::CLIENT_OVERRIDE_FILENAME.': the run file no longer has it');
        }
        $fs->writeFileReplacingPath($copy, $hardened['yaml'], $chown, '644');
    }

    /**
     * $relative as the checked-out commit has it, or null when git has no such file or there is no repository.
     */
    protected function committedVersion(string $projectDir, string $relative): ?string
    {
        try {
            $git = GitRepository::forProjectDir($this->dind, $projectDir);

            return $git->hasRepository() ? $git->readFromHead($relative) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The blanks a compose file leaves for whoever runs it (APP_SECRET: REPLACE_WITH_LONG_SECRET), filled with
     * per-account values, or the app refuses to boot or runs on a password published in its repository.
     *
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    private function fillPlaceholders(array $compose, ?DeployLogger $logger): array
    {
        $secrets = $this->dind->strategy()->secrets();
        $result = ComposePlaceholders::fill(
            $compose,
            $secrets->for('compose-placeholders'),
            $this->dind->publicAppUrl(),
            $secrets->userEnvVars()
        );

        foreach ($result['published'] as $key) {
            $logger?->info("Replaced the published placeholder in {$key} with a generated secret");
        }
        $generated = $secrets->notYetAnnounced($result['secrets']);
        if ($generated !== []) {
            $logger?->info(
                'This project ships its compose file with the passwords left blank. '
                .'Generated them for this account: '.implode(', ', $generated)
            );
        }
        if ($result['urls'] !== []) {
            $logger?->info(
                'Pointed the application address at its public URL: '.implode(', ', $result['urls'])
            );
        }

        return $result['compose'];
    }
}
