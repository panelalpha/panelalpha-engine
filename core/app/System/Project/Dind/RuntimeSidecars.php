<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeFileReferences;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\MovedImages;
use App\Lib\Deploy\Compose\RuntimeSidecars as ComposeSidecars;
use App\Lib\Deploy\Compose\ServiceDependencies;
use App\Lib\Deploy\Sidecar\EnvSidecars;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\Lib\Deploy\Source\GitUrl;
use App\System\Project\Dind as DindProject;

/**
 * Backing services a repo implies but does not run: from a local-dev compose file, an example template or
 * DATABASE_URL/REDIS_URL in .env, though {@see DeployStrategy} never runs the file they came from.
 */
class RuntimeSidecars
{
    /** Words a compose filename uses for a stack that is not the deployment. */
    private const NON_PRODUCTION_WORDS = ['dev', 'development', 'local', 'test', 'tests', 'testing', 'ci', 'e2e', 'debug'];

    /** Words a compose filename uses for the author's own deployment. */
    private const PRODUCTION_WORDS = ['prod', 'production'];

    /** Words a compose filename uses for the database a stack variant runs on. */
    private const DATABASE_WORDS = ['mysql', 'mariadb', 'postgres', 'postgresql', 'pgsql', 'sqlite', 'sqlite3', 'db'];

    /** The database words of a variant that needs no database service. */
    private const FILE_DATABASE_WORDS = ['sqlite', 'sqlite3'];

    private DindProject $dind;

    private ?SidecarPasswords $passwords = null;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * @return array{
     *   services: array<string, array<string, mixed>>,
     *   volumes: array<string, mixed>,
     *   env: array<string, string>,
     * }
     */
    public function runtimeSidecarsFromProject(string $projectDir): array
    {
        foreach (Paths::composeFileCandidates() as $candidate) {
            $path = $projectDir.'/'.$candidate;
            $raw = $this->dind->projectTree()->read($path);
            if ($raw === null || ! ComposeFileInspector::isLocalDevComposeYaml($raw)) {
                continue;
            }
            $found = $this->fromLocalDevCompose($projectDir, $candidate, $raw);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->fromTemplatesOrEnv($projectDir);
    }

    /**
     * A production compose file the repo ships beats its workstation stack (LinkAce's dev file adds caddy);
     * a stack that kept only a test suite still gives the app its settings (Zerobyte's APP_SECRET).
     *
     * @return array<string, mixed>|null
     */
    private function fromLocalDevCompose(string $projectDir, string $candidate, string $raw): ?array
    {
        $production = $this->fromTemplates(
            $projectDir,
            self::productionComposeFilenames($projectDir),
            " instead of the development {$candidate}"
        );
        if ($production !== null) {
            return $production;
        }
        $extracted = ComposeHarden::extractRuntimeSidecarsFromYaml(
            $raw,
            false,
            $this->imagePortLookup(),
            $this->projectIdentity(),
            $this->accountMemoryMb(),
            $this->placeholderSeed(),
            $this->passwords(),
            $this->dind->environment()->forInterpolation(),
            $this->dind->userModel()->username,
            $this->dind->userAppDirPath()
        );
        $this->logDroppedProxies($extracted);
        $this->logDroppedTestServices($extracted, $candidate);
        $suiteOnly = $extracted['services'] === [] && self::droppedATestSuite($extracted);
        if ($extracted['services'] !== [] || $suiteOnly) {
            $this->logKeptServices($extracted);

            return $extracted;
        }

        return null;
    }

    /** @param  array<string, mixed>  $extracted */
    private function logKeptServices(array $extracted): void
    {
        if ($extracted['services'] !== []) {
            $names = implode(', ', array_keys($extracted['services']));
            $this->dind->shell()->logger()?->info("Keeping runtime services from compose: {$names}");
        }
        foreach ($extracted['dropped_mounts'] ?? [] as $mount) {
            $this->dind->shell()->logger()?->info("Dropped the bind {$mount}: installed dependencies are not in a deployment's checkout");
        }
        $this->warnOfPublishedSecrets($extracted['published_secrets'] ?? []);
        foreach ($extracted['replaced_mounts'] ?? [] as $mount) {
            $this->dind->shell()->logger()?->info("Moved the datastore bind {$mount} to a named volume: its data does not belong in the checkout");
        }
        $this->logHardeningRemovals($extracted);
    }

    /**
     * Nothing live to learn from: a template the repo ships (compose.example.yml, docker-compose.mysql.yml,
     * …), else the services .env implies, else none.
     *
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}
     */
    private function fromTemplatesOrEnv(string $projectDir): array
    {
        $template = $this->fromTemplates($projectDir, self::exampleComposeFilenames($projectDir));
        if ($template !== null) {
            return $template;
        }

        return $this->fromEnv($projectDir) ?? ['services' => [], 'volumes' => [], 'env' => []];
    }

    /**
     * DATABASE_URL or REDIS_URL on localhost still needs a companion, or the app 500s (TanStack + Drizzle).
     * Read through the account's file layer: a 0600 .env is invisible to www-data.
     *
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}|null
     */
    private function fromEnv(string $projectDir): ?array
    {
        $fromEnv = EnvSidecars::fromProjectDir($projectDir, $this->dind->projectTree()->read(...), $this->passwords());
        if ($fromEnv['services'] !== []) {
            $names = implode(', ', array_keys($fromEnv['services']));
            $this->dind->shell()->logger()?->info(
                "Adding backing services inferred from .env: {$names}"
            );

            return $fromEnv;
        }

        return null;
    }

    /**
     * The first template of $candidates that yields a backing service, read for its datastores only.
     *
     * @param  list<string>  $candidates
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}|null
     */
    private function fromTemplates(string $projectDir, array $candidates, string $note = ''): ?array
    {
        $rootBuildNames = $this->rootBuildNames($projectDir);
        foreach ($candidates as $candidate) {
            $raw = $this->dind->projectTree()->read($projectDir.'/'.$candidate);
            if ($raw === null || $this->extendsAnotherFile($candidate, $raw)) {
                continue;
            }
            $extracted = ComposeHarden::extractRuntimeSidecarsFromYaml(
                $raw,
                true,
                $this->imagePortLookup(),
                $this->projectIdentity(),
                $this->accountMemoryMb(),
                $this->placeholderSeed(),
                $this->passwords(),
                $this->dind->environment()->forInterpolation(),
                $this->dind->userModel()->username,
                $this->dind->userAppDirPath(),
                $rootBuildNames
            );
            $this->logDroppedProxies($extracted);
            $this->logDroppedTestServices($extracted, $candidate);
            if ($extracted['services'] !== []) {
                $names = implode(', ', array_keys($extracted['services']));
                $this->dind->shell()->logger()?->info(
                    "Adding backing services described in {$candidate}{$note}: {$names}"
                );
                $this->warnOfPublishedSecrets($extracted['published_secrets'] ?? []);
                $this->logHardeningRemovals($extracted);

                return $extracted;
            }
        }

        return null;
    }

    /**
     * A slice naming services it does not define is a layer over another file, not a stack: Atlas's
     * `docker-compose.https.yml` gave the app a Caddy in front of it and no database.
     */
    private function extendsAnotherFile(string $candidate, string $raw): bool
    {
        $services = ComposeYaml::parse($raw)['services'] ?? null;
        $undefined = is_array($services) ? ServiceDependencies::undefinedIn($services) : [];
        if ($undefined === []) {
            return false;
        }
        $this->dind->shell()->logger()?->info(
            "Skipped {$candidate} as a source of backing services: it extends another compose file, naming "
            .implode(', ', $undefined).' without defining '.(count($undefined) === 1 ? 'it' : 'them')
        );

        return true;
    }

    /**
     * A secret-like variable harvested with its literal value tells every deploy of this repository what an
     * attacker can already read from it.
     *
     * @param  array<string, list<string>>  $byService
     */
    private function warnOfPublishedSecrets(array $byService): void
    {
        foreach ($byService as $service => $keys) {
            $vars = implode(', ', $keys);
            $this->dind->shell()->logger()?->warn(
                "Compose service {$service} sets {$vars} to a literal value: it is published in the repository and shared by every deploy of it"
            );
        }
    }

    /**
     * @param  array{dropped_proxies?: list<string>}  $extracted
     */
    private function logDroppedProxies(array $extracted): void
    {
        foreach ($extracted['dropped_proxies'] ?? [] as $proxy) {
            $this->dind->shell()->logger()?->info("Dropped service {$proxy}: the engine's proxy routes traffic to this app");
        }
    }

    /**
     * @param  array{dropped_test_services?: list<string>, dropped_test_matrix?: array{engines: int, databases?: list<string>, services: list<string>}|null}  $extracted
     */
    private static function droppedATestSuite(array $extracted): bool
    {
        return ($extracted['dropped_test_services'] ?? []) !== [] || ($extracted['dropped_test_matrix'] ?? null) !== null;
    }

    /**
     * @param  array{dropped_test_services?: list<string>, dropped_test_matrix?: array{engines: int, databases?: list<string>, services: list<string>}|null}  $extracted
     */
    private function logDroppedTestServices(array $extracted, string $file): void
    {
        $names = $extracted['dropped_test_services'] ?? [];
        if ($names !== []) {
            $this->dind->shell()->logger()?->info('Dropped test-suite services: '.implode(', ', $names));
        }
        $matrix = $extracted['dropped_test_matrix'] ?? null;
        if ($matrix !== null) {
            $this->dind->shell()->logger()?->info(self::droppedTestMatrixLine($matrix, $file));
        }
    }

    /**
     * The count is of the databases listed; what went with them is listed apart.
     *
     * @param  array{engines: int, databases?: list<string>, services: list<string>}  $matrix
     */
    public static function droppedTestMatrixLine(array $matrix, string $file): string
    {
        $databases = $matrix['databases'] ?? $matrix['services'];
        $others = array_values(array_diff($matrix['services'], $databases));
        $line = "Dropped a test matrix from {$file} (".count($databases).' SQL engines, none configured): '.implode(', ', $databases);

        return $others === [] ? $line : $line.'; with them: '.implode(', ', $others);
    }

    /**
     * @param  array{hardening_removed?: list<string>}  $extracted
     */
    private function logHardeningRemovals(array $extracted): void
    {
        foreach ($extracted['hardening_removed'] ?? [] as $what) {
            $this->dind->shell()->logger()?->warn(ComposeHarden::REMOVED_SOURCE.": {$what}");
        }
    }

    /**
     * `docker-compose.prod.yml`, `compose.production.yaml`: the author's own production stack, on disk.
     *
     * @return list<string>
     */
    private static function productionComposeFilenames(string $projectDir): array
    {
        return array_values(array_filter(
            self::exampleComposeFilenames($projectDir),
            static fn (string $name): bool => self::suffixWords($name, self::PRODUCTION_WORDS) !== []
        ));
    }

    /**
     * The words between `compose` and the extension that are in $words: `docker-compose.local-dev.yml`
     * gives `local`, `dev`.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function suffixWords(string $filename, array $words): array
    {
        $middle = preg_replace('/^(docker-)?compose\.|\.ya?ml$/i', '', strtolower($filename));
        $found = preg_split('/[._-]+/', (string) $middle) ?: [];

        return array_values(array_intersect($found, $words));
    }

    /**
     * Stack templates, then the `docker-compose.<x>.yml` slices on disk: production ones (`.prod`) first, so Atlas's
     * `.production` stack beats its `.https` proxy layer, and non-production ones (`.dev`, `.test`) last.
     *
     * @return list<string>
     */
    private static function exampleComposeFilenames(string $projectDir = ''): array
    {
        $names = ComposeFileInspector::COMPOSE_EXAMPLE_CANDIDATES;

        $projectDir = rtrim($projectDir, '/');
        if ($projectDir !== '' && is_dir($projectDir)) {
            $found = self::composeSlicesIn($projectDir);
            $nonProduction = array_values(array_filter(
                $found,
                static fn (string $name): bool => self::suffixWords($name, self::NON_PRODUCTION_WORDS) !== []
            ));
            $production = array_values(array_filter(
                $found,
                static fn (string $name): bool => self::suffixWords($name, self::PRODUCTION_WORDS) !== []
            ));
            $names = array_merge($names, $production, array_diff($found, $nonProduction, $production), $nonProduction);
            if (self::shipsAFileDatabaseVariant($found)) {
                $names = array_filter(
                    $names,
                    static fn (string $name): bool => self::suffixWords($name, self::DATABASE_WORDS) === []
                );
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * `docker-compose.sqlite.yml` beside `.mysql` and `.postgres` (Kanboard) makes them alternatives, and the one the
     * application runs without being told is the file database: adopting the MySQL slice switched Kanboard to MariaDB,
     * where its own migrations fail on a fresh schema.
     *
     * @param  list<string>  $slices
     */
    private static function shipsAFileDatabaseVariant(array $slices): bool
    {
        foreach ($slices as $slice) {
            if (self::suffixWords($slice, self::FILE_DATABASE_WORDS) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `docker-compose.*.yml` files on disk docker does not read by itself, never the engine's own output
     * or a recipe's override copied in, which are no stack template.
     *
     * @return list<string>
     */
    private static function composeSlicesIn(string $projectDir): array
    {
        $found = [];
        foreach (['docker-compose.*.yml', 'docker-compose.*.yaml', 'compose.*.yml', 'compose.*.yaml'] as $pattern) {
            foreach (glob($projectDir.'/'.$pattern) ?: [] as $path) {
                $base = basename($path);
                if (in_array($base, Paths::composeFileCandidates(), true)) {
                    continue;
                }
                if (Paths::isEngineComposeFile($path)) {
                    continue;
                }
                if ($base === Paths::CLIENT_OVERRIDE_FILENAME) {
                    continue;
                }
                $found[] = $base;
            }
        }

        return $found;
    }

    /**
     * The sidecars into the decision; without services only a test-suite-only file's app env, ranked below
     * what the strategy generates.
     *
     * @param  array<string, mixed>  $decision
     * @param array{
     *   services: array<string, array<string, mixed>>,
     *   volumes: array<string, mixed>,
     *   env: array<string, string>,
     * } $sidecars
     * @return array<string, mixed>
     */
    public function mergeRuntimeSidecars(array $decision, array $sidecars): array
    {
        $sidecars = $this->withoutAppService($sidecars);
        $sidecars['services'] = $this->withMovedImages($sidecars['services']);
        if (is_string($sidecars['build_image'] ?? null) && $sidecars['build_image'] !== '') {
            $decision['build_image'] = $sidecars['build_image'];
        }
        if (($sidecars['app_mounts'] ?? []) !== []) {
            $decision['app_mounts'] = $sidecars['app_mounts'];
        }
        if (($sidecars['app_aliases'] ?? []) !== []) {
            $decision['app_aliases'] = $sidecars['app_aliases'];
        }
        $decision = self::withDeclaredFiles($decision, $sidecars);
        if ($sidecars['services'] === []) {
            if (($sidecars['app_env'] ?? []) !== []) {
                $decision['env'] = array_merge($sidecars['app_env'], $decision['env'] ?? []);
            }

            return $decision;
        }
        $decision['sidecars'] = array_merge($sidecars['services'], $decision['sidecars'] ?? []);
        $decision['volumes'] = array_merge($sidecars['volumes'], $decision['volumes'] ?? []);
        $decision = self::withSidecarEnv($decision, $sidecars);
        $decision['depends_on'] = array_keys($sidecars['services']);

        return $decision;
    }

    /**
     * The `secrets:`/`configs:` the kept services and the app name, and the app's own secrets.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $sidecars
     * @return array<string, mixed>
     */
    private static function withDeclaredFiles(array $decision, array $sidecars): array
    {
        foreach (ComposeFileReferences::SECTIONS as $section) {
            if (is_array($sidecars[$section] ?? null) && $sidecars[$section] !== []) {
                $decision[$section] = array_merge($sidecars[$section], is_array($decision[$section] ?? null) ? $decision[$section] : []);
            }
        }
        if (($sidecars['app_secrets'] ?? []) !== []) {
            $decision['app_secrets'] = $sidecars['app_secrets'];
        }

        return $decision;
    }

    /**
     * Harvested app env < the strategy's < the sidecars' connection env, except an app-declared URL to a kept
     * sidecar with its credentials, whose driver (CTFd's mysql+pymysql://) the generic one drops.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $sidecars
     * @return array<string, mixed>
     */
    private static function withSidecarEnv(array $decision, array $sidecars): array
    {
        $generated = $decision['env'] ?? [];
        $decision['env'] = array_merge($sidecars['app_env'] ?? [], $decision['env'] ?? [], $sidecars['env']);
        foreach (self::urlsToKeptSidecars($sidecars['app_env'] ?? [], array_keys($sidecars['services'])) as $key => $url) {
            if (isset($sidecars['env'][$key]) && ! isset($generated[$key])
                && self::sameCredentials($url, $sidecars['env'][$key])
            ) {
                $decision['env'][$key] = $url;
            }
        }

        return $decision;
    }

    private static function sameCredentials(string $a, string $b): bool
    {
        foreach ([PHP_URL_USER, PHP_URL_PASS] as $part) {
            if (rawurldecode((string) parse_url($a, $part)) !== rawurldecode((string) parse_url($b, $part))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<int|string>  $services
     * @return array<string, string>
     */
    private static function urlsToKeptSidecars(array $env, array $services): array
    {
        $names = array_map(static fn ($name): string => strtolower((string) $name), $services);
        $urls = [];
        foreach ($env as $key => $value) {
            $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : null;
            if (is_string($host) && in_array(strtolower($host), $names, true)) {
                $urls[(string) $key] = $value;
            }
        }

        return $urls;
    }

    /**
     * A sidecar under the generated app's own name makes `app -> app`; the extractor renames genuine ones,
     * so one still here is the app itself.
     *
     * @param  array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}  $sidecars
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}
     */
    private function withoutAppService(array $sidecars): array
    {
        foreach (array_keys($sidecars['services']) as $name) {
            if (strcasecmp((string) $name, GeneratedCompose::APP_SERVICE) !== 0) {
                continue;
            }
            $this->dind->shell()->logger()?->info(
                "Dropping compose service {$name}: it is the application being deployed, not a backing service"
            );
            unset($sidecars['services'][$name]);
            foreach ($sidecars['services'] as $other => $service) {
                $sidecars['services'][$other] = ServiceDependencies::withoutDropped($service, [strtolower((string) $name) => true]);
            }
        }

        return $sidecars;
    }

    /**
     * A kept service on a tag its publisher moved runs it from where it went ({@see MovedImages}).
     *
     * @param  array<string, array<string, mixed>>  $services
     * @return array<string, array<string, mixed>>
     */
    private function withMovedImages(array $services): array
    {
        $result = MovedImages::apply($services, [], $this->imagePublished(...));
        foreach ($result['moved'] as $moved) {
            $this->dind->shell()->logger()?->info(MovedImages::line($moved));
        }

        return $result['services'];
    }

    /** Whether a registry publishes $image, null when that cannot be told. */
    protected function imagePublished(string $image): ?bool
    {
        return (new RegistryImageConfig)->isPublished($image);
    }

    /**
     * A database sidecar's password when nobody set one: per account, unless its databases were initialised
     * under the old `app`; decided on the first deploy that asks and stored with the account.
     */
    public function passwords(): SidecarPasswords
    {
        if ($this->passwords !== null) {
            return $this->passwords;
        }
        $user = $this->dind->userModel();
        $stored = $user->getDetails()[SidecarPasswords::DETAILS_KEY] ?? null;
        $decision = SidecarPasswords::decideMode(
            is_string($stored) ? $stored : null,
            is_string($stored) ? null : $this->accountHasVolumes()
        );
        if ($decision['store']) {
            $this->dind->freezeDeploySnapshot([SidecarPasswords::DETAILS_KEY => $decision['mode']]);
            $this->dind->shell()->logger()?->info($decision['mode'] === SidecarPasswords::MODE_LEGACY
                ? 'This account already holds data: database sidecars keep the legacy default password where the project sets none'
                : 'Database sidecars on this account get their own passwords where the project sets none');
        }

        return $this->passwords = SidecarPasswords::forMode(
            $decision['mode'],
            $this->dind->strategy()->secrets()->for('sidecar-passwords')
        );
    }

    /** Whether the account's own Docker holds any volume; null when it cannot be asked. */
    private function accountHasVolumes(): ?bool
    {
        try {
            return $this->dind->accountDaemon()->volumeNames(60) !== [];
        } catch (\Throwable) {
            return null;
        }
    }

    /** The seed {@see Strategy\UserComposeStrategy} fills compose placeholders from, so both paths agree. */
    private function placeholderSeed(): string
    {
        return $this->dind->strategy()->secrets()->for('compose-placeholders');
    }

    /** The account's own memory ceiling, so a project the operator raised gets services sized to match; null when none. */
    private function accountMemoryMb(): ?int
    {
        return $this->dind->userModel()->effectiveMemoryLimit();
    }

    /**
     * owner/repo of the repository deployed, so a compose file naming the project's own published image is
     * recognised as the app rather than started beside the copy we build.
     */
    private function projectIdentity(): ?string
    {
        $repoUrl = (string) $this->dind->userModel()->getGitRepo();

        return $repoUrl === '' ? null : GitUrl::ownerAndRepo($repoUrl);
    }

    /**
     * What the repository's compose files call the app they build from its root, so a template naming it is
     * not started beside our build when no repository URL matches (an archive or upload deploy).
     *
     * @return list<string>
     */
    private function rootBuildNames(string $projectDir): array
    {
        $names = [];
        foreach (array_unique([...Paths::composeFileCandidates(), ...self::exampleComposeFilenames($projectDir)]) as $candidate) {
            $raw = $this->dind->projectTree()->read($projectDir.'/'.$candidate);
            if ($raw !== null) {
                $names = [...$names, ...ComposeSidecars::rootBuildServiceNames($raw)];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Lets the classifier ask what an image says about itself, memoised so the same image twice costs one
     * round-trip.
     *
     * @return callable(string): list<int>
     */
    private function imagePortLookup(): callable
    {
        $inner = $this->dind->innerDocker();
        $seen = [];

        return static function (string $image) use ($inner, &$seen): array {
            if ($image === '') {
                return [];
            }
            if (! array_key_exists($image, $seen)) {
                $seen[$image] = $inner->declaredImagePorts($image);
            }

            return $seen[$image];
        };
    }
}
