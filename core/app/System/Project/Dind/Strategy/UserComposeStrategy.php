<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use Symfony\Component\Yaml\Yaml;

/**
 * The project's own compose file, made fit to host.
 *
 * Reads the customer's file and changes as little as it can: point a build
 * at the Dockerfile that actually exists, apply the hosting hardening, and
 * fill in the blanks the author left for whoever would run it. The result is
 * written to the run file (ADR-0001) — the customer's own file is never
 * touched, so it stays byte-identical to their repository.
 */
class UserComposeStrategy
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * @param array{compose_path: ?string, ...} $decision
     */
    public function apply(
        array $decision,
        ?AppConfig $appConfig,
        string $projectDir,
        ?string $chown,
        string $sourceLabel
    ): void {
        // Whatever the app config wrote is already on disk and already detected,
        // so there is one compose file here and no question of whose it is.
        $composePath = is_string($decision['compose_path'] ?? null) && $decision['compose_path'] !== ''
            ? $decision['compose_path']
            : $this->dind->userAppExistingComposeFilePath();

        if ($composePath === null) {
            $this->dind->strategy()->railpack()->applyRailpackOrFallback($projectDir, $chown, $sourceLabel);

            return;
        }

        $this->normalize($composePath, $projectDir, $chown);
        // A pre-existing .env means the platform's prepare already ran on an
        // earlier deploy.
        if (!$this->dind->system()->filesystem()->fileExists("{$projectDir}/.env")) {
            $prepare = $this->dind->strategy()->prepare();
            $prepare->run($projectDir, $prepare->manifestFor($decision), $appConfig);
        }
    }

    /**
     * Re-run {@see normalize()} on whatever compose file the project
     * currently ships, so an edit made after the first deploy is picked up
     * the next time the container starts (ticket 05: container actions
     * regenerate the run file before `up`/`pull`). A no-op when the project
     * has no compose file of its own to read.
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
     * Point missing compose dockerfile paths at the root Dockerfile, then
     * apply hosting harden (skip git hooks, skip demo seeds, restart).
     *
     * Reads $composePath, the project's own file, and writes the result to
     * the run file (ADR-0001) — the source is never touched.
     */
    private function normalize(string $composePath, string $projectDir, ?string $chown): void
    {
        $logger = $this->dind->shell()->logger();
        $system = $this->dind->system();
        $runPath = $this->dind->userAppComposeFilePath();
        $missing = ComposeFileInspector::missingComposeDockerfileRefs($composePath, $projectDir);
        $raw = $system->filesystem()->fileGetContents($composePath);
        // Through ComposeYaml, not Yaml::parse: this was the one unguarded
        // parse in the deploy path, and a construct Symfony rejects but Docker
        // accepts took the whole account with it -- the exception reached the
        // API verbatim and the rollback deleted the log before it could be
        // read. {@see ComposeYaml} rescues that shape and answers null for a
        // file that is genuinely unreadable.
        $parsed = ComposeYaml::parse($raw);
        if ($parsed === null) {
            throw new \InvalidArgumentException(
                'The compose file in this project could not be read as YAML.'
            );
        }
        if (!isset($parsed['services']) || !is_array($parsed['services'])) {
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    'Compose file builds from ' . $missing[0] . ' which does not exist.'
                );
            }

            // Nothing to harden, but the run file still has to exist for
            // whatever runs `compose up` against it — copy the source through
            // verbatim rather than leaving composeFileToRun() pointing at
            // nothing.
            $system->filesystem()->filePutContents($runPath, $raw, $chown, '644');

            return;
        }

        $fallbackDockerfile = is_file($projectDir . '/Dockerfile');
        foreach ($parsed['services'] as &$service) {
            if (!is_array($service) || !isset($service['build'])) {
                continue;
            }
            $build = $service['build'];
            if (!is_string($build) && !is_array($build)) {
                continue;
            }
            $abs = ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build);
            if ($abs === null || is_file($abs)) {
                continue;
            }
            $dockerfile = is_array($build)
                ? (string) ($build['dockerfile'] ?? 'Dockerfile')
                : 'Dockerfile';
            $canFallback = $fallbackDockerfile
                && is_array($build)
                && ComposeFileInspector::composeBuildContextIsProjectRoot($build);
            if (!$canFallback) {
                throw new \InvalidArgumentException(
                    'Compose file builds from ' . $dockerfile . ' which does not exist.'
                );
            }
            $service['build']['dockerfile'] = 'Dockerfile';
            $logger?->info("Compose dockerfile {$dockerfile} is missing; using Dockerfile");
        }
        unset($service);

        // Before port detection reads the file, so a bundled Traefik's :80 is
        // never taken for the app's port.
        $ingress = ComposeHarden::withoutHostIngress($parsed);
        $parsed = $ingress['compose'];
        foreach ($ingress['dropped'] as $line) {
            $logger?->info($line);
        }

        // The DinD proxy routes the domain to the account container on the
        // detected primary port; a service that only expose:s it binds nothing
        // there, so publish it explicitly or the domain 502s.
        $binding = ComposeHarden::withPublishedPrimaryPort($parsed);
        $parsed = $binding['compose'];
        if ($binding['published'] !== null) {
            $logger?->info("Published detected primary port {$binding['published']} so the domain reaches this app");
        }

        foreach (ComposeHarden::oneShotServices($parsed) as $name) {
            $logger?->info("Service {$name} runs once and exits; not restarting it");
        }

        // The account's own ceiling, so a project the operator has given more
        // memory actually gets it. Null when none is set, which keeps the
        // built-in defaults.
        $parsed = ComposeHarden::apply($parsed, $this->dind->userModel()->getMemoryLimit());
        $parsed = $this->fillPlaceholders($parsed, $logger);
        // A tracked .env's overrides (ADR-0001 D3). ProjectEnvironment decides
        // on every deploy whether the file should exist; this only keeps it
        // attached when the run file is regenerated without a deploy (`up`).
        if ($system->filesystem()->fileExists($projectDir . '/' . EngineArtifacts::ENV_OVERRIDES)) {
            [$parsed, ] = ComposeEnvFiles::attach($parsed, EngineArtifacts::ENV_OVERRIDES);
        }
        $system->filesystem()->filePutContents($runPath, Yaml::dump($parsed, 6, 2), $chown, '644');
        $this->dind->strategy()->installRailsHostInitializer($projectDir, $chown);
        $logger?->info('Hardened compose for hosting (resource limits, restart policy, isolation)');
    }

    /**
     * A project's own compose file is written for someone who will edit it
     * before running it: APP_SECRET: REPLACE_WITH_LONG_SECRET, POSTGRES_
     * PASSWORD: STRONG_DB_PASSWORD. Nobody edits it here, so those blanks are
     * filled in with per-account values before the stack starts — otherwise
     * the app either refuses to boot or runs on a password published in its
     * own repository.
     *
     * @param array<string, mixed> $compose
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
        if ($result['secrets'] !== []) {
            $logger?->info(
                'This project ships its compose file with the passwords left blank. '
                . 'Generated them for this account: ' . implode(', ', $result['secrets'])
            );
        }
        if ($result['urls'] !== []) {
            $logger?->info(
                'Pointed the application address at its public URL: ' . implode(', ', $result['urls'])
            );
        }

        return $result['compose'];
    }
}
