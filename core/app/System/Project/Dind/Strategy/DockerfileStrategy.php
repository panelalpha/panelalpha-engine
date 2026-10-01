<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Compose\DeployCompose;

/**
 * A repository that ships its own Dockerfile.
 *
 * The lightest strategy there is, and deliberately so: the author wrote that
 * Dockerfile and their choices stand, so nothing is generated but the compose
 * file that builds it and `<Dockerfile>.dockerignore`, which keeps the engine's
 * files and `.git` out of the build context ({@see keepEngineFilesOutOfContext()}).
 * The one exception is Rails, whose boot contract needs things the Dockerfile
 * cannot supply for itself — a secret_key_base that lives in a gitignored
 * master.key, and TLS settings that would otherwise send HSTS for a
 * self-signed hosting certificate.
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
     * Writes `<Dockerfile>.dockerignore` so the build context holds the
     * repository and not the engine's files beside it. Called once `.env` is
     * final, since whether it can be left out depends on what it holds.
     * {@see BuildContextIgnoreWriter}.
     *
     * @param array<string, mixed> $decision
     */
    public function keepEngineFilesOutOfContext(array $decision, string $projectDir, ?string $chown): void
    {
        $dockerfile = is_string($decision['dockerfile'] ?? null) ? $decision['dockerfile'] : 'Dockerfile';
        $this->dind->strategy()->contextIgnore()->write($projectDir, $dockerfile, false, $chown);
    }

    /**
     * The container paths this project's Dockerfile declares as volumes.
     *
     * Read through the account's own file layer rather than the host's: the
     * checkout belongs to the account and is not readable as www-data.
     *
     * @return list<string>
     */
    private function declaredVolumes(string $projectDir, string $dockerfile): array
    {
        $contents = $this->dind->projectTree()->readIn($projectDir, $dockerfile);

        return $contents === null ? [] : DockerfileFinder::declaredVolumesIn($contents);
    }

    /**
     * @param array{dockerfile: ?string, port_hint: ?int, ...} $decision
     */
    public function apply(array $decision, string $projectDir, ?string $chown): void
    {
        $strategy = $this->dind->strategy();
        $dockerfile = $decision['dockerfile'] ?? 'Dockerfile';
        $port = (int) ($decision['port_hint'] ?? self::DEFAULT_PORT);
        if ($port <= 0) {
            $port = self::DEFAULT_PORT;
        }

        $sidecars = $strategy->sidecars()->runtimeSidecarsFromProject($projectDir);
        // The manifest's `database: mysql` (Kimai), provisioned before the
        // compose file is written because its URL is part of the app's env.
        $database = $strategy->database()->forService($decision, $sidecars['services']);

        $decision = $strategy->sidecars()->mergeRuntimeSidecars(
            [
                'build_args' => $this->buildArgs($decision),
                'env' => array_merge(
                    ComposeHarden::urlEnvironment($this->dind->publicAppUrl(), [
                        $this->dind->projectTree()->readIn($projectDir, '.env'),
                        $this->dind->projectTree()->readIn($projectDir, '.env.example'),
                    ]),
                    $database['env'] ?? [],
                    $this->environment($projectDir),
                    $strategy->entrypoint()->deployPhaseEnvironment()
                ),
                // Paths the image declares as volumes. Without naming them,
                // Docker invents an anonymous volume per path -- data the
                // engine cannot see, back up, or keep across a recreate.
                'dockerfile_volumes' => $this->declaredVolumes($projectDir, $dockerfile),
            ] + (isset($database['extra_hosts']) ? ['extra_hosts' => $database['extra_hosts']] : []),
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
     * What a repo's own Dockerfile cannot provide for itself.
     *
     * For a generic app this stays empty: the author wrote that Dockerfile
     * and their choices stand. Rails is the exception.
     *
     * @return array<string, string>
     */
    private function environment(string $projectDir): array
    {
        $ruby = $this->dind->strategy()->ruby();

        return $ruby->app($projectDir)->isRails() ? $ruby->proxyEnvironment($projectDir) : [];
    }

    /**
     * The manifest's `build_args`, passed to the repository Dockerfile.
     * Kimai picks its Apache variant with `ARG BASE`; without it the default
     * FPM variant is built and its port 9000 speaks FastCGI, not HTTP.
     *
     * @param array<string, mixed> $decision
     * @return array<string, string>
     */
    private function buildArgs(array $decision): array
    {
        $declared = $decision['build_args'] ?? null;
        $args = [];
        foreach (is_array($declared) ? $declared : [] as $name => $value) {
            if (is_string($name) && $name !== '' && (is_string($value) || is_int($value)) && (string) $value !== '') {
                $args[$name] = (string) $value;
            }
        }
        if ($args !== []) {
            $pairs = array_map(static fn (string $n, string $v): string => "{$n}={$v}", array_keys($args), $args);
            $this->dind->shell()->logger()?->info('Building the repository Dockerfile with ' . implode(', ', $pairs));
        }

        return $args;
    }
}
