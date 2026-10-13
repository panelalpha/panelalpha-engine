<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\GitRepository;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeInterpolation;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\ComposeRequiredEnv;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Compose\PublicUrlEnvironment;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\Lib\Deploy\Env\EnvTemplates;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Project\ApiAccess;

/**
 * The .env files the application is deployed with: `.env.default` is the base the project shipped,
 * `.env` what the container reads, and `.env.panelalpha` the engine's values (generated secrets,
 * and the env_vars when the repository tracks its own `.env`, which is then left as committed, ADR-0001).
 */
class ProjectEnvironment
{
    /** Project detail: the env_vars keys the last deploy merged into .env. */
    private const MERGED_OVERRIDES = 'env_override_keys';

    /** Mode of files that can hold generated secrets: ~/project is traversable by every uid. */
    private const OWNER_ONLY = '600';

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Write .env.default from the current base, then merge the account's non-empty env_vars
     * (an empty one keeps the base) over it, the env_vars winning over generated values.
     *
     * @param ?string $composePath the compose file detection chose, when it is not at the root
     */
    public function apply(?string $composePath = null): void
    {
        $user = $this->dind->userModel();
        $fs = $this->dind->system()->filesystem();
        $projectDir = $this->dind->userAppDirPath();
        $envPath = "{$projectDir}/.env";
        $exampleName = EnvTemplates::first(static fn (string $name): bool => $fs->fileExists("{$projectDir}/{$name}")) ?? '.env.example';
        $defaultPath = "{$projectDir}/.env.default";
        $chown = $user->getChownString();
        $logger = $this->dind->shell()->logger();
        $overrides = self::nonEmptyValues($user->getEnvVars());

        [$baseContents, $source, $removed] = $this->baseEnv($user, $projectDir, $exampleName, $overrides, $logger);
        [$baseContents, $keyAdded] = $this->withLaravelAppKey($user, $baseContents, $source, $overrides, $logger);
        [$baseContents, $requiredUrls, $generated] = $this->withComposeRequiredValues($baseContents, $overrides, $logger);
        if ($baseContents !== null) {
            $fs->filePutContents($defaultPath, $baseContents, $chown, self::OWNER_ONLY);
        }

        $tracked = $overrides !== [] && $this->envIsTracked();
        $services = $this->writeEngineValues(
            (new ApiAccess($this->dind->system()))->envFor($user) + ($tracked ? $overrides + $generated : $generated),
            $projectDir,
            $chown
        );

        if ($tracked) {
            $this->keepTrackedEnv($user, $overrides, $services, $projectDir, $chown, $logger);
        } elseif ($overrides !== []) {
            $this->mergeOverridesIntoEnv($user, $baseContents, $overrides, $projectDir, $composePath, $chown, $logger);
        } else {
            if ($baseContents !== null && $this->baseEnvNeedsWriting($envPath, $removed !== [] || $keyAdded, $requiredUrls !== [])) {
                $this->writeEnv($envPath, $baseContents, $chown);
            }
            $this->materializeNestedEnvExamples($projectDir, $chown);
            $this->withNestedComposeEnv($projectDir, $composePath, $chown);
            $logger?->info("Using default environment variables (source: {$source})");
            self::recordOverrides($user, false, []);
        }
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private static function nonEmptyValues(array $values): array
    {
        return array_filter($values, static fn ($value): bool => $value !== '');
    }

    /**
     * The base `.env`: the one the checkout has, else one made from the env template, else none.
     *
     * @param array<string, string> $overrides
     * @return array{0: ?string, 1: string, 2: list<string>} contents, its source, the keys no longer overridden
     */
    private function baseEnv(\App\Models\User $user, string $projectDir, string $exampleName, array $overrides, ?DeployLogger $logger): array
    {
        $envPath = "{$projectDir}/.env";
        $example = $this->readExampleWithoutEnv($envPath, "{$projectDir}/{$exampleName}", $exampleName, $logger);
        if ($this->dind->system()->filesystem()->fileExists($envPath)) {
            [$contents, $removed] = $this->baseFromSurvivingEnv($user, $envPath, "{$projectDir}/.env.default", $overrides, $logger);

            return [$contents, 'after-clone', $removed];
        }
        if ($example !== null) {
            return [$this->baseFromExample($example, $exampleName, $overrides, $logger), $exampleName, []];
        }

        return [null, 'none', []];
    }

    /**
     * The env template's contents, when there is no `.env` to start from; null when it is
     * not text Docker Compose can read.
     */
    private function readExampleWithoutEnv(string $envPath, string $examplePath, string $exampleName, ?DeployLogger $logger): ?string
    {
        $fs = $this->dind->system()->filesystem();
        if ($fs->fileExists($envPath) || !$fs->fileExists($examplePath)) {
            return null;
        }

        $example = EnvFile::asUtf8((string) $fs->fileGetContents($examplePath));
        if ($example === null) {
            $logger?->warn("Ignored {$exampleName}: it is not a text file Docker Compose can read (NUL bytes in an unrecognised encoding)");
        }

        return $example;
    }

    /**
     * A `.env` that survives redeploys (archive, upload) still holds the overrides merged last
     * time: back to the base first, so one removed since is gone and .env.default stays the base.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} the base, the keys no longer overridden
     */
    private function baseFromSurvivingEnv(\App\Models\User $user, string $envPath, string $defaultPath, array $overrides, ?DeployLogger $logger): array
    {
        $fs = $this->dind->system()->filesystem();
        $contents = $fs->fileGetContents($envPath);
        [$contents, $restored] = self::withoutMergedOverrides(
            (string) $contents,
            $fs->fileExists($defaultPath) ? (string) $fs->fileGetContents($defaultPath) : '',
            self::mergedOverrideKeys($user)
        );
        $removed = array_values(array_diff($restored, array_keys($overrides)));
        if ($removed !== []) {
            $logger?->info('No longer set as environment variables, so put back to the base .env: ' . implode(', ', $removed));
        }

        return [$contents, $removed];
    }

    /**
     * The env template made into a base `.env`: its published secrets replaced, and what
     * the compose file, the account's address or the image answer better taken out or filled.
     *
     * @param array<string, string> $overrides
     */
    private function baseFromExample(string $example, string $exampleName, array $overrides, ?DeployLogger $logger): string
    {
        [$contents, $replaced] = $this->withTemplateSecretsReplaced($example, $overrides);
        foreach ($replaced as $key) {
            $logger?->info("Replaced the published placeholder in {$key} from {$exampleName} with a generated secret");
        }
        $contents = $this->withoutComposeDefaultedKeys($contents);
        $contents = $this->fillPublicUrlBlanks($contents, $exampleName, $overrides, $logger);
        [$contents, $blanks] = self::withoutTemplatePlaceholders($contents, $overrides);
        if ($blanks !== []) {
            $logger?->info(
                "Left to the image, which sets them: {$exampleName} had blanks to fill in for "
                . implode(', ', $blanks)
            );
        }

        return self::withoutComposeRejectedLines($contents, $exampleName, $logger);
    }

    /**
     * A template's APP_KEY and published signing keys, replaced with values seeded per account.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the published keys replaced
     */
    private function withTemplateSecretsReplaced(string $contents, array $overrides = []): array
    {
        $seed = $this->secretSeed();

        return self::withoutPublishedSecrets(self::withGeneratedSecrets($contents, $seed), $seed, $overrides);
    }

    /**
     * The account's address in the template's blank public-URL keys. The address is a
     * lookup, so it is asked for only when there is a blank to fill.
     *
     * @param array<string, string> $overrides
     */
    private function fillPublicUrlBlanks(string $contents, string $exampleName, array $overrides, ?DeployLogger $logger): string
    {
        if (self::blankPublicUrlKeys($contents, $overrides) === []) {
            return $contents;
        }

        [$contents, $urls] = self::withPublicUrlBlanksFilled(
            $contents,
            $this->dind->publicAppUrl(),
            $overrides
        );
        if ($urls !== []) {
            $logger?->info("Filled the blank public-URL keys in {$exampleName} with this account's address: " . implode(', ', $urls));
        }

        return $contents;
    }

    /**
     * A generated APP_KEY for a Laravel `.env` that has none: key:generate only rewrites an
     * existing line. A `.env` the repository tracks is left as committed.
     *
     * @param array<string, string> $overrides
     * @return array{0: ?string, 1: bool} contents, whether the key was added
     */
    private function withLaravelAppKey(\App\Models\User $user, ?string $contents, string $source, array $overrides, ?DeployLogger $logger): array
    {
        if ($user->getDeployStrategy() !== Strategies::LARAVEL
            || isset($overrides['APP_KEY'])
            || ($source === 'after-clone' && $this->envIsTracked())
        ) {
            return [$contents, false];
        }

        [$contents, $keyAdded] = self::withAppKeyLine($contents, $this->secretSeed());
        if ($keyAdded) {
            $logger?->info('Added a generated APP_KEY to .env: it had none, and artisan key:generate only fills an existing line');
        }

        return [$contents, $keyAdded];
    }

    /**
     * What the compose file requires and nothing set: its own public URL goes into the base,
     * secrets are returned for `.env.panelalpha`, since a non-empty `.env` stays in the build
     * context and an image that copies its context into a docroot serves it.
     *
     * @param array<string, string> $overrides
     * @return array{0: ?string, 1: array<string, string>, 2: array<string, string>} contents, the URL keys set, the generated secrets
     */
    private function withComposeRequiredValues(?string $contents, array $overrides, ?DeployLogger $logger): array
    {
        $required = $this->composeRequiredValues($contents, $overrides);
        $requiredUrls = array_filter($required, ComposePlaceholders::isRequiredOwnUrlKey(...), ARRAY_FILTER_USE_KEY);
        $generated = array_diff_key($required, $requiredUrls);
        if ($requiredUrls !== []) {
            $contents = EnvFile::merge($contents ?? '', $requiredUrls);
        }
        $announce = $this->dind->strategy()->secrets()->notYetAnnounced(array_map('strval', array_keys($generated)));
        if ($announce !== []) {
            $logger?->info(
                'This project\'s compose file needs values nobody set. '
                . 'Generated them for this account, in ' . EngineArtifacts::ENV_OVERRIDES . ': ' . implode(', ', $announce)
            );
        }
        if ($requiredUrls !== []) {
            $logger?->info('Pointed the application address at its public URL: ' . implode(', ', array_keys($requiredUrls)));
        }

        return [$contents, $requiredUrls, $generated];
    }

    /**
     * Write `.env.panelalpha`, read only by compose inside the account, or remove one an earlier
     * deploy left, which would otherwise keep reapplying values removed since.
     *
     * @param array<string, string> $values
     * @return list<string> the services that load it
     */
    private function writeEngineValues(array $values, string $projectDir, ?string $chown): array
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $path = $projectDir . '/' . EngineArtifacts::ENV_OVERRIDES;
        if ($values !== []) {
            $fs->filePutContents($path, EnvFile::merge('', $values), $chown, self::OWNER_ONLY);

            return $this->syncRunFileEnvOverrides(true);
        }

        if ($fs->fileExists($path)) {
            $system->exec(['sudo', 'rm', '-f', $path]);
        }
        $this->syncRunFileEnvOverrides(false);

        return [];
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string> $services
     */
    private function keepTrackedEnv(\App\Models\User $user, array $overrides, array $services, string $projectDir, ?string $chown, ?DeployLogger $logger): void
    {
        $keys = array_keys($overrides);
        $logger?->info(
            'The repository tracks .env, so it is left as committed. Using user-provided environment variables ('
            . count($keys)
            . ' keys) from ' . EngineArtifacts::ENV_OVERRIDES
            . ($services === [] ? '' : ', loaded after .env by: ' . implode(', ', $services))
            . ': ' . implode(', ', $keys)
        );
        $logger?->warn(
            'Code that reads .env from disk will not see these overrides, including build-time readers '
            . 'such as Vite or Next.js. Only the container environment carries them.'
        );
        $this->materializeNestedEnvExamples($projectDir, $chown);
        self::recordOverrides($user, true, []);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function mergeOverridesIntoEnv(\App\Models\User $user, ?string $baseContents, array $overrides, string $projectDir, ?string $composePath, ?string $chown, ?DeployLogger $logger): void
    {
        $envPath = "{$projectDir}/.env";
        $this->writeEnv($envPath, EnvFile::merge($baseContents ?? '', $overrides), $chown);
        $keys = array_keys($overrides);
        $logger?->info(
            'Using user-provided environment variables ('
            . count($keys)
            . ' keys overridden): '
            . implode(', ', $keys)
        );
        $this->materializeNestedEnvExamples($projectDir, $chown);
        $this->withNestedComposeEnv($projectDir, $composePath, $chown);
        self::recordOverrides($user, true, array_keys($overrides));
    }

    /** Whether the base must replace `.env`: missing, changed above, or given its own URL while untracked. */
    private function baseEnvNeedsWriting(string $envPath, bool $baseChanged, bool $urlsAdded): bool
    {
        return !$this->dind->system()->filesystem()->fileExists($envPath)
            || $baseChanged
            || ($urlsAdded && !$this->envIsTracked());
    }

    /**
     * @param list<string> $mergedKeys
     */
    private static function recordOverrides(\App\Models\User $user, bool $used, array $mergedKeys): void
    {
        $user->setDetails(['used_custom_env_vars' => $used, self::MERGED_OVERRIDES => $mergedKeys]);
        $user->save();
    }

    private function writeEnv(string $path, string $contents, ?string $chown): void
    {
        $this->dind->system()->filesystem()->filePutContents($path, $contents, $chown, $this->envMode($path));
    }

    /**
     * The mode `.env` is written with: $new for a file not there yet (owner-only, it holds
     * secrets), an existing file's own mode never wider than 0644.
     */
    private function envMode(string $envPath, int $new = 0600): string
    {
        $current = $this->dind->system()->filesystem()->mode($envPath);

        return sprintf('%o', $current === null ? $new : $current & 0644);
    }

    private function secretSeed(): string
    {
        return $this->dind->strategy()->secrets()->for('compose-placeholders');
    }

    private function usesComposeStrategy(): bool
    {
        $strategy = $this->dind->userModel()->getDeployStrategy();

        return $strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD;
    }

    /**
     * The project's own compose file and the run file, when they differ.
     *
     * @return array<int, string>
     */
    private function composeFilePaths(): array
    {
        return array_unique(array_filter([
            $this->dind->userAppExistingComposeFilePath(),
            $this->dind->userAppComposeFilePath(),
        ]));
    }

    /**
     * The `.env` compose interpolates the run file with, once {@see apply()}
     * has written it: a host port taken from it is the port compose publishes.
     *
     * @return array<string, string>
     */
    public function forPortDetection(): array
    {
        return self::variables($this->dind->projectTree()->readIn($this->dind->userAppDirPath(), '.env') ?? '');
    }

    /**
     * What compose may interpolate the project's files with, for the hardener
     * to check mount sources against: it runs before {@see apply()} writes
     * `.env`, so this is every value that can end up there.
     *
     * @return array<string, list<?string>>
     */
    public function forInterpolation(): array
    {
        $tree = $this->dind->projectTree();
        $projectDir = $this->dind->userAppDirPath();
        $example = null;
        EnvTemplates::first(static function (string $name) use ($tree, $projectDir, &$example): bool {
            $example = $tree->readIn($projectDir, $name);

            return $example !== null;
        });

        return ComposeInterpolation::environment(
            $tree->readIn($projectDir, '.env'),
            $example,
            $this->dind->userModel()->getEnvVars()
        );
    }

    /**
     * Secrets the compose file interpolates from `.env` that nothing supplies
     * ({@see ComposeRequiredEnv}); left out, compose refuses the project or a database exits.
     *
     * @param array<string, string> $overrides
     * @return array<string, string> NAME => value for the keys nothing set
     */
    private function composeRequiredValues(?string $contents, array $overrides): array
    {
        if (!$this->usesComposeStrategy()) {
            return [];
        }

        $files = $this->composeFilesRequiringEnv();
        if ($files === []) {
            return [];
        }

        return ComposeRequiredEnv::missing(
            $files,
            $overrides + self::variables($contents ?? ''),
            $this->secretSeed(),
            fn (): ?string => self::httpUrl($this->dind->publicAppUrl())
        );
    }

    /**
     * @return list<array{dir: string, yaml: string}>
     */
    private function composeFilesRequiringEnv(): array
    {
        $fs = $this->dind->system()->filesystem();
        $projectDir = rtrim($this->dind->userAppDirPath(), '/');
        $read = static function (string $relative) use ($fs, $projectDir): ?string {
            $path = $projectDir . '/' . $relative;

            return $fs->fileExists($path) ? (string) $fs->fileGetContents($path) : null;
        };

        $files = [];
        foreach ($this->composeFilePaths() as $composePath) {
            if (str_starts_with($composePath, $projectDir . '/')) {
                $files = array_merge($files, ComposeRequiredEnv::collect(substr($composePath, strlen($projectDir) + 1), $read));
            }
        }

        return $files;
    }

    private static function httpUrl(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /**
     * A compose file kept under docker/ reads docker/.env when run on its own,
     * but the run file is started from the root and reads the root .env. The
     * nested file's keys go under the root's, the root's values winning.
     */
    private function withNestedComposeEnv(string $projectDir, ?string $composePath, ?string $chown): void
    {
        if ($composePath === null
            || !$this->usesComposeStrategy()
            || ($dir = NestedCompose::relativeDir($composePath, $projectDir)) === null
        ) {
            return;
        }

        $fs = $this->dind->system()->filesystem();
        $nestedPath = "{$projectDir}/{$dir}/.env";
        if (!$fs->fileExists($nestedPath)) {
            return;
        }
        $rootPath = "{$projectDir}/.env";
        $root = $fs->fileExists($rootPath) ? (string) $fs->fileGetContents($rootPath) : '';
        $added = array_diff_key(
            self::variables((string) $fs->fileGetContents($nestedPath)),
            self::variables($root)
        );
        if ($added === []) {
            return;
        }

        $this->writeEnv($rootPath, EnvFile::merge($root, $added), $chown);
        $this->dind->shell()->logger()?->info(
            "Compose reads {$dir}/.env when run from {$dir}/; carried its values into .env for the run from the project root: "
            . implode(', ', array_keys($added))
        );
    }

    /**
     * @return array<string, string>
     */
    private static function variables(string $contents): array
    {
        $vars = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $vars[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $vars;
    }

    /**
     * The row's key when it is a variable, '' otherwise.
     *
     * @param array<string, mixed> $row
     */
    private static function variableKey(array $row): string
    {
        return ($row['type'] ?? '') === 'variable' ? (string) ($row['key'] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hasBlankValue(array $row): bool
    {
        return trim(trim((string) ($row['value'] ?? '')), '"\'') === '';
    }

    /**
     * Whether git tracks `.env`; no git, or git failing to answer, reads as untracked.
     * Protected so a test can force either answer without a live DinD shell.
     */
    protected function envIsTracked(): bool
    {
        $git = new GitRepository($this->dind);
        if (!$git->hasRepository()) {
            return false;
        }

        try {
            return $git->trackedAmong(['.env']) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Attach `.env.panelalpha` to the run file's services that load `.env`,
     * or detach it from all of them. Rewrites the run file only when that
     * changes it.
     *
     * @return list<string> the services it is attached to
     */
    private function syncRunFileEnvOverrides(bool $attach): array
    {
        $fs = $this->dind->system()->filesystem();
        $runPath = $this->dind->userAppComposeFilePath();
        if (!$fs->fileExists($runPath)) {
            return [];
        }

        $raw = (string) $fs->fileGetContents($runPath);
        $compose = ComposeYaml::parse($raw);
        if ($compose === null) {
            return [];
        }

        if ($attach) {
            [$updated, $services] = ComposeEnvFiles::attach($compose, EngineArtifacts::ENV_OVERRIDES);
        } else {
            [$updated, ] = ComposeEnvFiles::detach($compose, EngineArtifacts::ENV_OVERRIDES);
            $services = [];
        }

        if ($updated !== $compose) {
            $fs->filePutContents(
                $runPath,
                ComposeYaml::dump($updated, $raw, 6, 2),
                $this->dind->userModel()->getChownString(),
                EngineArtifacts::RUN_COMPOSE_MODE
            );
        }

        return $services;
    }

    /**
     * Comment out the template keys the compose file defaults with `${VAR:-default}`: through
     * `env_file:` even a template's value beats that default. Read from the project's own compose
     * file, or the run file once a redeploy has produced one; the run file may not exist yet.
     */
    private function withoutComposeDefaultedKeys(string $contents): string
    {
        if (!$this->usesComposeStrategy()) {
            return $contents;
        }

        $fs = $this->dind->system()->filesystem();
        $composeFile = $this->dind->userAppExistingComposeFilePath() ?? $this->dind->userAppComposeFilePath();
        if (!$fs->fileExists($composeFile)) {
            return $contents;
        }

        [$trimmed, $dropped] = self::deferToComposeDefaults(
            $contents,
            (string) $fs->fileGetContents($composeFile)
        );

        if ($dropped !== []) {
            $this->dind->shell()->logger()?->info(
                'Left to ' . basename($composeFile) . ', which defaults them: ' . implode(', ', $dropped)
            );
        }

        return $trimmed;
    }

    /**
     * The env file without the keys the compose file defaults (`${VAR:-default}`), commented out;
     * a bare `${VAR}` keeps the template's value.
     *
     * @return array{0: string, 1: list<string>} contents, the keys deferred
     */
    public static function deferToComposeDefaults(string $envContents, string $composeRaw): array
    {
        preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):-/', $composeRaw, $matches);
        $defaulted = array_flip($matches[1] ?? []);
        if ($defaulted === []) {
            return [$envContents, []];
        }

        $dropped = [];
        $rows = [];
        foreach (EnvFile::parse($envContents) as $row) {
            $key = self::variableKey($row);
            if ($key !== '' && isset($defaulted[$key])) {
                $dropped[] = $key;
                $rows[] = [
                    'type' => 'comment',
                    'text' => '# ' . $key . '= # left to the compose file, which supplies a default',
                ];
                continue;
            }
            $rows[] = $row;
        }

        return $dropped === [] ? [$envContents, []] : [EnvFile::serialise($rows), $dropped];
    }

    /**
     * A template's APP_KEY, whatever it says, replaced: a key published in a repository is
     * everybody's, and an injected blank beats the image's. Seeded so it is stable across
     * redeploys; a null seed gives a random key.
     */
    private static function withGeneratedSecrets(string $contents, ?string $seed = null): string
    {
        foreach (EnvFile::parse($contents) as $row) {
            if (self::variableKey($row) === 'APP_KEY') {
                return EnvFile::merge($contents, [
                    'APP_KEY' => $seed === null
                        ? 'base64:' . base64_encode(random_bytes(32))
                        : ComposePlaceholders::publishedSecret('APP_KEY', $seed),
                ]);
            }
        }

        return $contents;
    }

    /**
     * An APP_KEY line for a Laravel .env that has none, or only a blank one.
     * Same seeded value as {@see withGeneratedSecrets()}, so stable across redeploys.
     *
     * @return array{0: string, 1: bool} contents, whether the key was set
     */
    public static function withAppKeyLine(?string $contents, string $seed): array
    {
        foreach (EnvFile::parse($contents ?? '') as $row) {
            if (self::variableKey($row) === 'APP_KEY' && !self::hasBlankValue($row)) {
                return [(string) $contents, false];
            }
        }

        return [EnvFile::merge($contents ?? '', ['APP_KEY' => ComposePlaceholders::publishedSecret('APP_KEY', $seed)]), true];
    }

    /**
     * Signing keys a `.env.example` sets to a well-known placeholder (Saleor's
     * SECRET_KEY=changeme), with the same seed and rule as the compose path.
     * APP_KEY is left to withGeneratedSecrets; keys the account set are left to its env_vars.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the keys replaced
     */
    public static function withoutPublishedSecrets(string $contents, string $seed, array $overrides = []): array
    {
        $generated = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = self::variableKey($row);
            if ($key === '' || $key === 'APP_KEY' || isset($overrides[$key])
                || !ComposePlaceholders::isPublishedSecret($key, (string) ($row['value'] ?? ''))
            ) {
                continue;
            }
            $generated[$key] = ComposePlaceholders::publishedSecret($key, $seed, (string) ($row['value'] ?? ''));
        }

        return $generated === []
            ? [$contents, []]
            : [EnvFile::merge($contents, $generated), array_keys($generated)];
    }

    /**
     * A template's fill-in-the-blank values, commented out: through `env_file:` they would
     * beat the image's own `ENV` default. Keys the account set are a person's answer and kept.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the keys left out
     */
    public static function withoutTemplatePlaceholders(string $contents, array $overrides = []): array
    {
        $blanks = [];
        $rows = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = self::variableKey($row);
            if ($key !== '' && !isset($overrides[$key])
                && ComposePlaceholders::isTemplatePlaceholder((string) ($row['value'] ?? ''))
            ) {
                $blanks[] = $key;
                $rows[] = [
                    'type' => 'comment',
                    'text' => '# ' . $key . '=' . (string) ($row['value'] ?? '')
                        . ' # a blank in .env.example, left to the image',
                ];
                continue;
            }
            $rows[] = $row;
        }

        return $blanks === [] ? [$contents, []] : [EnvFile::serialise($rows), $blanks];
    }

    /**
     * An example is copied into a file Compose parses strictly; a line meant
     * for a shell (`unset A B`) would stop the stack, so it is commented out.
     */
    private static function withoutComposeRejectedLines(string $contents, string $name, ?DeployLogger $logger): string
    {
        [$contents, $lines] = EnvFile::withoutComposeRejectedLines($contents);
        if ($lines !== []) {
            $logger?->info(
                "Commented out {$name} line(s) " . implode(', ', $lines)
                . ': they are not KEY=VALUE, and Docker Compose refuses to read an env file that has them'
            );
        }

        return $contents;
    }

    /**
     * A public-URL key `.env.example` leaves blank, set to the account's address: set-but-empty
     * is worse than unset. Only the keys that always mean the site's own URL are filled.
     *
     * @param array<string, string> $overrides
     * @return array{0: string, 1: list<string>} contents, the keys filled
     */
    public static function withPublicUrlBlanksFilled(string $contents, ?string $publicUrl, array $overrides = []): array
    {
        $url = is_string($publicUrl) ? trim($publicUrl) : '';
        if (preg_match('#^https?://#i', $url) !== 1) {
            return [$contents, []];
        }

        $filled = [];
        $rows = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (self::isBlankPublicUrlRow($row, $overrides)) {
                $row['value'] = $url;
                $filled[] = (string) $row['key'];
            }
            $rows[] = $row;
        }

        return $filled === [] ? [$contents, []] : [EnvFile::serialise($rows), $filled];
    }

    /**
     * @param array<string, string> $overrides
     * @return list<string>
     */
    public static function blankPublicUrlKeys(string $contents, array $overrides = []): array
    {
        $keys = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (self::isBlankPublicUrlRow($row, $overrides)) {
                $keys[] = (string) $row['key'];
            }
        }

        return $keys;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $overrides
     */
    private static function isBlankPublicUrlRow(array $row, array $overrides): bool
    {
        $key = self::variableKey($row);

        return $key !== ''
            && in_array($key, PublicUrlEnvironment::blankFillKeys(), true)
            && !isset($overrides[$key])
            && self::hasBlankValue($row);
    }

    private function materializeNestedEnvExamples(string $projectDir, ?string $chown): void
    {
        $logger = $this->dind->shell()->logger();
        foreach (EnvFile::nestedEnvExampleCopies($projectDir) as $copy) {
            $this->materializeNestedEnvExample($copy, $projectDir, $chown, $logger);
        }
        $this->materializeComposeEnvFiles($projectDir, $chown);
    }

    /**
     * One nested `.env` from its example: a template's secrets are replaced as at the root, a
     * real `.env` used as the source is copied as it is. A new copy is 0644, since an image may
     * COPY it and read it as a non-root user.
     *
     * @param array{example: string, dest: string, relative: string} $copy
     */
    private function materializeNestedEnvExample(array $copy, string $projectDir, ?string $chown, ?DeployLogger $logger): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        if (is_dir($copy['dest'])) {
            $system->exec(['sudo', 'rm', '-rf', $copy['dest']]);
        }
        $contents = $copy['example'] === ''
            ? ''
            : (EnvFile::asUtf8((string) $fs->fileGetContents($copy['example'])) ?? '');
        $replaced = [];
        if ($contents !== '' && self::isTemplateFile($copy['example'])) {
            [$contents, $replaced] = $this->withTemplateSecretsReplaced($contents);
        }
        if ($contents !== '') {
            $contents = self::withoutComposeRejectedLines(
                $contents,
                ltrim(substr($copy['example'], strlen($projectDir)), '/'),
                $logger
            );
        }
        $fs->filePutContents($copy['dest'], $contents, $chown, $this->envMode($copy['dest'], 0644));
        $logger?->info(
            $copy['example'] === ''
                ? 'Created empty ' . $copy['relative'] . ' (required by compose env_file)'
                : 'Created ' . $copy['relative'] . ' from ' . basename($copy['example'])
        );
        foreach ($replaced as $key) {
            $logger?->info("Replaced the published placeholder in {$key} of {$copy['relative']} with a generated secret");
        }
    }

    private static function isTemplateFile(string $path): bool
    {
        return preg_match('/\.(?:example|sample|template|dist)$/', $path) === 1 || EnvTemplates::isTemplate($path);
    }

    /**
     * Create, empty, every `env_file:` the project's or the run compose file names that is
     * missing: Compose V2 refuses to start without it, even when every value has a default.
     */
    private function materializeComposeEnvFiles(string $projectDir, ?string $chown): void
    {
        $fs = $this->dind->system()->filesystem();
        $logger = $this->dind->shell()->logger();
        foreach ($this->composeFilePaths() as $composePath) {
            if (!$fs->fileExists($composePath)) {
                continue;
            }
            $raw = $fs->fileGetContents($composePath);
            foreach (EnvFile::composeEnvFileRelativePathsFromYaml($raw) as $relative) {
                $dest = $projectDir . '/' . $relative;
                if ($fs->fileExists($dest)) {
                    continue;
                }
                $fs->filePutContents($dest, '', $chown, '644');
                $logger?->info("Created empty {$relative} (required by compose env_file)");
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function mergedOverrideKeys(\App\Models\User $user): array
    {
        $keys = $user->getDetails()[self::MERGED_OVERRIDES] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * $keys back to their value in the base .env (.env.default, written
     * before the overrides were merged), or out of .env when the base has none.
     *
     * @param list<string> $keys
     * @return array{0: string, 1: list<string>} contents, the keys changed
     */
    public static function withoutMergedOverrides(string $contents, string $base, array $keys): array
    {
        if ($keys === []) {
            return [$contents, []];
        }
        $baseRows = [];
        foreach (EnvFile::parse($base) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $baseRows[(string) $row['key']] = $row;
            }
        }
        $rows = [];
        $changed = [];
        foreach (EnvFile::parse($contents) as $row) {
            $key = ($row['type'] ?? '') === 'variable' ? (string) $row['key'] : null;
            if ($key === null || !in_array($key, $keys, true)) {
                $rows[] = $row;
                continue;
            }
            $changed[$key] = true;
            if (isset($baseRows[$key])) {
                $rows[] = $baseRows[$key];
            }
        }

        return $changed === [] ? [$contents, []] : [EnvFile::serialise($rows), array_keys($changed)];
    }
}
