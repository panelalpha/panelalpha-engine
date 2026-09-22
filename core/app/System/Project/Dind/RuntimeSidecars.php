<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\ServiceDependencies;
use App\Lib\Deploy\Sidecar\EnvSidecars;
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
    private DindProject $dind;

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
            $extracted = ComposeHarden::extractRuntimeSidecarsFromYaml(
                $raw,
                false,
                $this->imagePortLookup(),
                $this->projectIdentity(),
                $this->accountMemoryMb(),
                $this->placeholderSeed()
            );
            if ($extracted['services'] !== []) {
                $names = implode(', ', array_keys($extracted['services']));
                $this->dind->shell()->logger()?->info("Keeping runtime services from compose: {$names}");

                return $extracted;
            }
        }

        // Nothing live to learn from: fall back to a template the repo ships
        // (compose.example.yml, docker-compose.mysql.yml, …).
        foreach (self::exampleComposeFilenames($projectDir) as $candidate) {
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
                $this->placeholderSeed()
            );
            if ($extracted['services'] !== []) {
                $names = implode(', ', array_keys($extracted['services']));
                $this->dind->shell()->logger()?->info(
                    "Adding backing services described in {$candidate}: {$names}"
                );

                return $extracted;
            }
        }

        // No compose at all — DATABASE_URL / REDIS_URL on localhost still need a
        // companion container or the app 500s on every request (TanStack + Drizzle).
        $fromEnv = EnvSidecars::fromProjectDir($projectDir);
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
                    if ($base === Paths::COMPOSE_OVERRIDE_FILENAME) {
                        continue;
                    }
                    $names[] = $base;
                }
            }
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
        $decision['env'] = array_merge($sidecars['app_env'] ?? [], $decision['env'] ?? [], $sidecars['env']);
        $decision['depends_on'] = array_keys($sidecars['services']);

        return $decision;
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
        return $this->dind->userModel()->getMemoryLimit();
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
