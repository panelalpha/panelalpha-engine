<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\RepositoryAppTerminal;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Detect\DockerfileStages;
use App\Lib\Deploy\Source\GitUrl;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Paths;

/**
 * A repository that ships its own Dockerfile: only the compose file that builds it and its ignore file are written,
 * plus the environment Rails cannot supply for itself (a secret_key_base and TLS settings).
 */
class DockerfileStrategy
{
    private const DEFAULT_PORT = 80;

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Writes `<Dockerfile>.dockerignore` ({@see BuildContextIgnoreWriter}) once `.env` is final, since whether
     * it can be left out of the build context depends on what it holds.
     *
     * @param  array<string, mixed>  $decision
     */
    public function keepEngineFilesOutOfContext(array $decision, string $projectDir, ?string $chown): void
    {
        $dockerfile = is_string($decision['dockerfile'] ?? null) ? $decision['dockerfile'] : 'Dockerfile';
        $this->dind->strategy()->contextIgnore()->write($projectDir, $dockerfile, false, $chown);
    }

    /**
     * The paths the Dockerfile declares as volumes, named so Docker does not invent anonymous ones the engine
     * cannot keep. Read through the account's file layer: the checkout is not readable as www-data.
     *
     * @return list<string>
     */
    private function declaredVolumes(string $projectDir, string $dockerfile): array
    {
        $contents = $this->dind->projectTree()->readIn($projectDir, $dockerfile);

        return $contents === null ? [] : DockerfileFinder::declaredVolumesIn($contents);
    }

    /**
     * The manifest's `database: mysql` (Kimai) is provisioned before the compose file, as its URL is part of the env.
     *
     * @param  array{dockerfile: ?string, port_hint: ?int, ...}  $decision
     */
    public function apply(array $decision, string $projectDir, ?string $chown): void
    {
        $strategy = $this->dind->strategy();
        $dockerfile = $decision['dockerfile'] ?? 'Dockerfile';
        $port = $this->port($decision);

        $sidecars = $strategy->sidecars()->runtimeSidecarsFromProject($projectDir);
        $database = $strategy->database()->forService($decision, $sidecars['services']);

        $decision = $strategy->sidecars()->mergeRuntimeSidecars(
            $this->service($decision, $projectDir, $dockerfile, $database),
            $sidecars
        );
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::dockerfile(
                $dockerfile,
                $port,
                $strategy->composeDecision($decision)
            ),
            $chown
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function port(array $decision): int
    {
        $port = (int) ($decision['port_hint'] ?? self::DEFAULT_PORT);
        if ($port <= 0) {
            $port = self::DEFAULT_PORT;
        }

        return $port;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private function service(array $decision, string $projectDir, $dockerfile, array $database): array
    {
        return [
            'build_args' => $this->buildArgs($decision),
            'build_cache_from' => (new ProjectBuildCache($this->dind))->cacheFrom($projectDir, $dockerfile),
            'build_target' => $this->buildTarget($projectDir, $dockerfile),
            'env' => array_merge(
                ComposeHarden::urlEnvironment($this->dind->publicAppUrl(), [
                    $this->dind->projectTree()->readIn($projectDir, '.env'),
                    $this->dind->projectTree()->readIn($projectDir, '.env.example'),
                ]),
                $database['env'] ?? [],
                $this->environment($projectDir),
                $this->dind->strategy()->entrypoint()->deployPhaseEnvironment()
            ),
            'dockerfile_volumes' => $this->declaredVolumes($projectDir, $dockerfile),
        ] + (isset($database['extra_hosts']) ? ['extra_hosts' => $database['extra_hosts']] : [])
            + $this->terminal($projectDir, $dockerfile);
    }

    /**
     * {@see DockerfileStages::productionTarget()}, logged; null builds the last stage, as Docker does.
     */
    private function buildTarget(string $projectDir, string $dockerfile): ?string
    {
        $contents = $this->dind->projectTree()->readIn($projectDir, $dockerfile);
        $target = $contents === null ? null : DockerfileStages::productionTarget($contents);
        if ($target !== null) {
            $this->dind->shell()->logger()?->info(
                "Building stage {$target} of {$dockerfile}: the last stage is a development one, and {$target} starts the app"
            );
        }

        return $target;
    }

    /**
     * `tty` / `stdin_open` from the repository's own compose service for this
     * Dockerfile, read from the root and from the Dockerfile's directory.
     *
     * @return array<string, true>
     */
    private function terminal(string $projectDir, string $dockerfile): array
    {
        $dir = dirname($dockerfile);
        $dirs = array_unique(['', $dir === '.' ? '' : $dir.'/']);
        $files = [];
        foreach ($dirs as $dir) {
            foreach (Paths::composeFileCandidates() as $name) {
                if (! Paths::isEngineComposeFile($projectDir.'/'.$dir.$name)) {
                    $files[$dir.$name] = $this->dind->projectTree()->readIn($projectDir, $dir.$name);
                }
            }
        }
        $repoUrl = (string) $this->dind->userModel()->getGitRepo();
        $settings = RepositoryAppTerminal::settings(
            $dockerfile,
            $files,
            $repoUrl === '' ? null : GitUrl::ownerAndRepo($repoUrl)
        );
        if ($settings !== []) {
            $this->dind->shell()->logger()?->info(
                'Running the app with '.implode(' and ', array_map(static fn (string $k): string => "{$k}: true", array_keys($settings)))
                .', as the repository\'s compose file does'
            );
        }

        return $settings;
    }

    /**
     * What a repo's own Dockerfile cannot provide for itself: nothing for a generic app, Rails' boot contract for Rails.
     *
     * @return array<string, string>
     */
    private function environment(string $projectDir): array
    {
        $ruby = $this->dind->strategy()->ruby();

        return $ruby->app($projectDir)->isRails() ? $ruby->proxyEnvironment($projectDir) : [];
    }

    /**
     * The manifest's `build_args` for the repository Dockerfile: Kimai picks its Apache variant with `ARG BASE`,
     * without which its FPM variant's port 9000 speaks FastCGI, not HTTP.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string>
     */
    private function buildArgs(array $decision): array
    {
        $args = self::declaredBuildArgs($decision);
        if ($args !== []) {
            $pairs = array_map(static fn (string $n, string $v): string => "{$n}={$v}", array_keys($args), $args);
            $this->dind->shell()->logger()?->info('Building the repository Dockerfile with '.implode(', ', $pairs));
        }

        return $args;
    }

    /**
     * The build args a manifest declares, as the deploy passes them; the host
     * fills a {@see BuildCache} with the same, or no step that reads one hits.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string>
     */
    public static function declaredBuildArgs(array $decision): array
    {
        $declared = $decision['build_args'] ?? null;
        $args = [];
        foreach (is_array($declared) ? $declared : [] as $name => $value) {
            if (is_string($name) && $name !== '' && (is_string($value) || is_int($value)) && (string) $value !== '') {
                $args[$name] = (string) $value;
            }
        }

        return $args;
    }
}
