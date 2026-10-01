<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\ServiceDependencies;
use App\Lib\Deploy\Sidecar\EnvSidecars;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\Lib\Deploy\Source\GitUrl;

/**
 * Backing services (databases, caches) a repo implies but does not run itself
 * — read from a live/local-dev compose file it ships, an example compose
 * template, or inferred from DATABASE_URL/REDIS_URL in .env — so the deploy
 * has the sidecars the app expects even though {@see DeployStrategy} never
 * runs the file they came from.
 */
class RuntimeSidecars
{
    /** Words a compose filename uses for a stack that is not the deployment. */
    private const NON_PRODUCTION_WORDS = ['dev', 'development', 'local', 'test', 'tests', 'testing', 'ci', 'e2e', 'debug'];

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
            $path = $projectDir . '/' . $candidate;
            $raw = $this->dind->projectTree()->read($path);
            if ($raw === null || !ComposeFileInspector::isLocalDevComposeYaml($raw)) {
                continue;
            }
            // The repo says what production runs beside the app: that beats
            // the workstation stack (LinkAce's dev file adds caddy, a second
            // database and buggregator; its production file does not).
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
            if ($extracted['services'] !== []) {
                $names = implode(', ', array_keys($extracted['services']));
                $this->dind->shell()->logger()?->info("Keeping runtime services from compose: {$names}");
                foreach ($extracted['dropped_mounts'] ?? [] as $mount) {
                    $this->dind->shell()->logger()?->info("Dropped the bind {$mount}: installed dependencies are not in a deployment's checkout");
                }

                return $extracted;
            }
        }

        // Nothing live to learn from: fall back to a template the repo ships
        // (compose.example.yml, docker-compose.mysql.yml, …).
        $template = $this->fromTemplates($projectDir, self::exampleComposeFilenames($projectDir));
        if ($template !== null) {
            return $template;
        }

        // No compose at all — DATABASE_URL / REDIS_URL on localhost still need a
        // companion container or the app 500s on every request (TanStack + Drizzle).
        // Through the account's file layer: a 0600 .env is invisible to www-data.
        $fromEnv = EnvSidecars::fromProjectDir($projectDir, $this->dind->projectTree()->read(...), $this->passwords());
        if ($fromEnv['services'] !== []) {
            $names = implode(', ', array_keys($fromEnv['services']));
            $this->dind->shell()->logger()?->info(
                "Adding backing services inferred from .env: {$names}"
            );

            return $fromEnv;
        }

        return ['services' => [], 'volumes' => [], 'env' => []];
    }

    /**
     * The first template of $candidates that yields a backing service, read
     * for its datastores only.
     *
     * @param list<string> $candidates
     * @return array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>}|null
     */
    private function fromTemplates(string $projectDir, array $candidates, string $note = ''): ?array
    {
        foreach ($candidates as $candidate) {
            $raw = $this->dind->projectTree()->read($projectDir . '/' . $candidate);
            if ($raw === null) {
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
                $this->dind->userAppDirPath()
            );
            if ($extracted['services'] !== []) {
                $names = implode(', ', array_keys($extracted['services']));
                $this->dind->shell()->logger()?->info(
                    "Adding backing services described in {$candidate}{$note}: {$names}"
                );

                return $extracted;
            }
        }

        return null;
    }

    /**
     * `docker-compose.prod.yml`, `compose.production.yaml`: the author's own
     * production stack, present on disk.
     *
     * @return list<string>
     */
    private static function productionComposeFilenames(string $projectDir): array
    {
        return array_values(array_filter(
            self::exampleComposeFilenames($projectDir),
            static fn (string $name): bool => self::suffixWords($name, ['prod', 'production']) !== []
        ));
    }

    /**
     * The words between `compose` and the extension that are in $words:
     * `docker-compose.local-dev.yml` gives `local`, `dev`.
     *
     * @param list<string> $words
     * @return list<string>
     */
    private static function suffixWords(string $filename, array $words): array
    {
        $middle = preg_replace('/^(docker-)?compose\.|\.ya?ml$/i', '', strtolower($filename));
        $found = preg_split('/[._-]+/', (string) $middle) ?: [];

        return array_values(array_intersect($found, $words));
    }

    /**
     * Filenames a repo uses as a stack template rather than a live compose.
     *
     * Listed here as well as on ComposeFileInspector so a php-fpm worker
     * that still has the previous ComposeFileInspector in opcache does not
     * 500 on an undefined constant mid-rebuild. Also picks up
     * `docker-compose.<engine>.yml` slices when present on disk.
     *
     * @return list<string>
     */
    private static function exampleComposeFilenames(string $projectDir = ''): array
    {
        if (defined(ComposeFileInspector::class . '::COMPOSE_EXAMPLE_CANDIDATES')) {
            /** @var list<string> $names */
            $names = ComposeFileInspector::COMPOSE_EXAMPLE_CANDIDATES;
        } else {
            $names = [
                'compose.example.yml',
                'compose.example.yaml',
                'docker-compose.example.yml',
                'docker-compose.example.yaml',
                'compose.sample.yml',
                'docker-compose.sample.yml',
                'compose.yml.example',
                'docker-compose.yml.example',
                'docker-compose.yml.dist',
                'compose.yml.dist',
                'docker-compose.mysql.yml',
                'docker-compose.postgres.yml',
                'docker-compose.db.yml',
            ];
        }

        $projectDir = rtrim($projectDir, '/');
        if ($projectDir !== '' && is_dir($projectDir)) {
            $found = [];
            foreach (['docker-compose.*.yml', 'docker-compose.*.yaml', 'compose.*.yml', 'compose.*.yaml'] as $pattern) {
                foreach (glob($projectDir . '/' . $pattern) ?: [] as $path) {
                    $base = basename($path);
                    if (in_array($base, Paths::composeFileCandidates(), true)) {
                        continue;
                    }
                    // The engine's own compose output can match this glob
                    // (a reserved name still shaped like docker-compose.*.yml)
                    // and must never be read back as the client's sidecar template.
                    if (Paths::isEngineComposeFile($path)) {
                        continue;
                    }
                    // A recipe's own override we just copied in, not a stack template.
                    if ($base === Paths::CLIENT_OVERRIDE_FILENAME) {
                        continue;
                    }
                    $found[] = $base;
                }
            }
            // A file its name marks as not for production (`.dev`, `.test`,
            // `.ci`) is read last, so it only speaks when nothing else does.
            $nonProduction = array_values(array_filter(
                $found,
                static fn (string $name): bool => self::suffixWords($name, self::NON_PRODUCTION_WORDS) !== []
            ));
            $names = array_merge($names, array_diff($found, $nonProduction), $nonProduction);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $decision
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
        if (is_string($sidecars['build_image'] ?? null) && $sidecars['build_image'] !== '') {
            $decision['build_image'] = $sidecars['build_image'];
        }
        if (($sidecars['app_mounts'] ?? []) !== []) {
            $decision['app_mounts'] = $sidecars['app_mounts'];
        }
        if (($sidecars['app_aliases'] ?? []) !== []) {
            $decision['app_aliases'] = $sidecars['app_aliases'];
        }
        if ($sidecars['services'] === []) {
            return $decision;
        }
        $decision['sidecars'] = array_merge($sidecars['services'], $decision['sidecars'] ?? []);
        $decision['volumes'] = array_merge($sidecars['volumes'], $decision['volumes'] ?? []);
        // Harvested app env < what the strategy generates < sidecar connection
        // env; the account's env_vars go on top later, in composeDecision().
        $generated = $decision['env'] ?? [];
        $decision['env'] = array_merge($sidecars['app_env'] ?? [], $decision['env'] ?? [], $sidecars['env']);
        // Except a connection URL the app's own service declared for a kept
        // sidecar with the credentials it runs with: it carries the driver the
        // app ships (CTFd's mysql+pymysql://), which the generic one drops.
        foreach (self::urlsToKeptSidecars($sidecars['app_env'] ?? [], array_keys($sidecars['services'])) as $key => $url) {
            if (isset($sidecars['env'][$key]) && !isset($generated[$key])
                && self::sameCredentials($url, $sidecars['env'][$key])
            ) {
                $decision['env'][$key] = $url;
            }
        }
        $decision['depends_on'] = array_keys($sidecars['services']);

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
     * @param array<string, string> $env
     * @param list<int|string> $services
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
     * A sidecar under the generated app's own name makes `app -> app`. The
     * extractor renames genuine ones, so one still here is the app itself.
     *
     * @param array{services: array<string, array<string, mixed>>, volumes: array<string, mixed>, env: array<string, string>} $sidecars
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
     * The password a database sidecar gets when nobody set one (engine#189):
     * per account, unless the account's databases were initialised under the
     * old `app` and would lock the app out if it changed. Decided on the
     * first deploy that asks and stored with the account.
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
            $out = $this->dind->shell()->execAsUserQuiet(['docker', 'volume', 'ls', '-q'], [], 60);
        } catch (\Throwable) {
            return null;
        }

        return trim($out) !== '';
    }

    /** The seed {@see Strategy\UserComposeStrategy} fills compose placeholders from, so both paths agree. */
    private function placeholderSeed(): string
    {
        return $this->dind->strategy()->secrets()->for('compose-placeholders');
    }


    /**
     * The account's own memory ceiling, so a project the operator has raised
     * gets services sized to match. Null when none is set.
     */
    private function accountMemoryMb(): ?int
    {
        return $this->dind->userModel()->effectiveMemoryLimit();
    }

    /**
     * owner/repo of the repository being deployed, so a compose file that
     * references the project's own published image is recognised as the
     * application rather than started beside the copy we build.
     */
    private function projectIdentity(): ?string
    {
        $repoUrl = (string) $this->dind->userModel()->getGitRepo();

        return $repoUrl === '' ? null : GitUrl::ownerAndRepo($repoUrl);
    }

    /**
     * Lets the classifier ask what an image says about itself, memoised so a
     * compose file with the same image twice costs one round-trip, not two.
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
            if (!array_key_exists($image, $seen)) {
                $seen[$image] = $inner->declaredImagePorts($image);
            }

            return $seen[$image];
        };
    }
}
