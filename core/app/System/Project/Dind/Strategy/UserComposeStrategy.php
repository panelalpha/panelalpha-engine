<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeExtends;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeInclude;
use App\Lib\Deploy\Compose\ComposeOverride;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NamedVolumes;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\Env\ComposeEnvFiles;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\Source\GitRepository;

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
     * Called before the app config writes files or runs its prepare hook, so
     * a deploy with no git history (an archive) can still tell the
     * repository's override from one the recipe wrote.
     */
    public function noteRepositoryOverride(string $projectDir): void
    {
        $fs = $this->dind->system()->filesystem();
        $source = rtrim($projectDir, '/') . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $this->repositoryOverride = $fs->fileExists($source) ? $fs->fileGetContents($source) : null;
        $this->repositoryOverrideNoted = true;
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
            $prepare->run($projectDir, $prepare->manifestFor($decision, $appConfig), $appConfig);
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
        // Mount sources may be written as `${VAR}`; the hardener checks what they interpolate to.
        $env = $this->dind->environment()->forInterpolation();
        $replaced = basename($composePath) === EngineArtifacts::APP_CONFIG_COMPOSE;
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
        // The run file sits at the root, so a file kept under docker/ has its
        // relative paths rewritten to mean the same from there (engine#91).
        $nested = NestedCompose::relativeDir($composePath, $projectDir);
        if ($nested !== null) {
            $parsed = NestedCompose::rebase($parsed, $nested);
            $logger?->info("Running {$nested}/" . basename($composePath) . ' from the project root, its relative paths rewritten to match');
        }
        // Included services are hardened and scanned like the file's own;
        // left as `include:` they ran exactly as written.
        $included = [];
        if (array_key_exists('include', $parsed)) {
            $read = fn (string $relative): ?string => $this->dind->projectTree()->read(rtrim($projectDir, '/') . '/' . $relative);
            ['compose' => $parsed, 'sources' => $included] = ComposeInclude::flatten($parsed, $read);
            $logger?->info('Merged the files this compose file includes into the run file');
        }
        // A service's `extends:` is merged into it before hardening; left as
        // written, the extended service's escapes were merged back in by
        // Compose at run time, past the hardener.
        if (isset($parsed['services']) && is_array($parsed['services'])) {
            $fs = $system->filesystem();
            $read = function (string $relative) use ($fs, $projectDir): ?string {
                $path = rtrim($projectDir, '/') . '/' . $relative;

                return $fs->fileExists($path) ? $fs->fileGetContents($path) : null;
            };
            $parsed = ComposeExtends::resolve($parsed, $read);
        }
        if (!isset($parsed['services']) || !is_array($parsed['services'])) {
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    'Compose file builds from ' . $missing[0] . ' which does not exist.'
                );
            }

            $this->writeClientOverride($projectDir, $chown, $logger, $replaced, $env, []);
            // Nothing to harden, but the run file still has to exist for
            // whatever runs `compose up` against it — copy the source through
            // verbatim rather than leaving composeFileToRun() pointing at
            // nothing.
            $system->filesystem()->filePutContents(
                $runPath,
                $included === [] ? $raw : ComposeYaml::dump($parsed, $raw, 6, 2, ...$included),
                $chown,
                EngineArtifacts::RUN_COMPOSE_MODE
            );

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
        $services = $parsed['services'];
        $ingress = ComposeHarden::withoutHostIngress($parsed);
        $parsed = $ingress['compose'];
        foreach ($ingress['dropped'] as $line) {
            $logger?->info($line);
        }
        // After the drops: an override naming a dropped service would bring it
        // back as a fragment with no image.
        $this->writeClientOverride(
            $projectDir,
            $chown,
            $logger,
            $replaced,
            $env,
            array_values(array_diff(array_map('strval', array_keys($services ?? [])), array_map('strval', array_keys($parsed['services']))))
        );

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

        foreach ($parsed['services'] as $name => $service) {
            if (is_array($service) && ServiceHardener::requestsMemlockUlimit($service)) {
                $logger?->info("Removed ulimits.memlock from service {$name}: an account cannot raise its locked-memory limit");
            }
            if (is_array($service) && ServiceHardener::needsStartPeriodUnderCap($service)) {
                $logger?->info("Gave {$name}'s healthcheck a 60s start period: its CPU is capped and the check allows no slow start");
            }
        }

        ['compose' => $parsed, 'replaced' => $unset] = NamedVolumes::forUnsetSources($parsed, $env);
        foreach ($unset as $what) {
            $logger?->info("A mount source variable is not set, so it is a named volume: {$what}");
        }

        // The account's own ceiling, so a project the operator has given more
        // memory actually gets it. Null when none is set, which keeps the
        // built-in defaults.
        $hardened = ComposeHarden::applyReporting($parsed, $this->dind->userModel()->effectiveMemoryLimit(), null, $env, $this->dind->userModel()->username, $this->dind->userAppDirPath());
        $parsed = $hardened['compose'];
        foreach ($hardened['removed'] as $what) {
            $logger?->warn(ComposeHarden::REMOVED_SOURCE . ": {$what}");
        }
        $parsed = $this->fillPlaceholders($parsed, $logger);
        // A tracked .env's overrides (ADR-0001 D3). ProjectEnvironment decides
        // on every deploy whether the file should exist; this only keeps it
        // attached when the run file is regenerated without a deploy (`up`).
        if ($system->filesystem()->fileExists($projectDir . '/' . EngineArtifacts::ENV_OVERRIDES)) {
            [$parsed, ] = ComposeEnvFiles::attach($parsed, EngineArtifacts::ENV_OVERRIDES);
        }
        $system->filesystem()->filePutContents($runPath, ComposeYaml::dump($parsed, $raw, 6, 2, ...$included), $chown, EngineArtifacts::RUN_COMPOSE_MODE);
        $this->dind->strategy()->installRailsHostInitializer($projectDir, $chown);
        $logger?->info('Hardened compose for hosting (resource limits, restart policy, isolation)');
    }

    /**
     * The same `<Dockerfile>.dockerignore` a repository Dockerfile gets, for
     * every `build:` in the run file: without it `.git` and the engine's files
     * went into the image, and a Dockerfile copying its context into a docroot
     * served them.
     */
    public function keepEngineFilesOutOfContext(string $projectDir, ?string $chown): void
    {
        $raw = $this->dind->projectTree()->read($this->dind->userAppComposeFilePath());
        $parsed = $raw === null ? null : ComposeYaml::parse($raw);
        if (!is_array($parsed['services'] ?? null)) {
            return;
        }

        $projectDir = rtrim($projectDir, '/');
        $written = [];
        foreach ($parsed['services'] as $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            if ((!is_string($build) && !is_array($build))
                || ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build) === null
            ) {
                continue;
            }
            $context = trim((string) (is_string($build) ? $build : ($build['context'] ?? '.')), '/');
            $context = ($context === '' || $context === '.') ? '' : (string) preg_replace('#^(\./)+#', '', $context);
            $dockerfile = is_array($build) && is_string($build['dockerfile'] ?? null) && $build['dockerfile'] !== ''
                ? $build['dockerfile']
                : 'Dockerfile';
            $contextDir = $context === '' ? $projectDir : $projectDir . '/' . $context;
            if (str_starts_with($dockerfile, '/') || isset($written[$contextDir . "\0" . $dockerfile])) {
                continue;
            }
            $written[$contextDir . "\0" . $dockerfile] = true;
            $this->dind->strategy()->contextIgnore()->write($contextDir, $dockerfile, false, $chown);
        }
    }

    /**
     * The repository's own `docker-compose.override.yml`, which compose layers
     * over the run file, copied with its escapes removed to a name the engine
     * owns ({@see Paths::composeFiles()} layers the copy). It used to be
     * layered as written, bringing back `privileged:` and host mounts the run
     * file had just been cleared of (engine#48, item 9).
     *
     * @param array<string, list<?string>> $env
     * @param list<string> $droppedServices services the run file no longer has
     */
    private function writeClientOverride(string $projectDir, ?string $chown, ?DeployLogger $logger, bool $replaced, array $env, array $droppedServices): void
    {
        $fs = $this->dind->system()->filesystem();
        $source = rtrim($projectDir, '/') . '/' . Paths::CLIENT_OVERRIDE_FILENAME;
        $copy = rtrim($projectDir, '/') . '/' . EngineArtifacts::RUN_CLIENT_OVERRIDE;
        $contents = $fs->fileExists($source) ? $fs->fileGetContents($source) : null;
        // The repository's override belongs to the compose file an app config
        // replaced; one a recipe wrote (prepare hook, files/) is still layered.
        $committed = $contents !== null && $replaced
            ? $this->committedVersion($projectDir, Paths::CLIENT_OVERRIDE_FILENAME)
                ?? ($this->repositoryOverrideNoted ? $this->repositoryOverride : null)
            : null;
        $stale = $committed !== null && rtrim($committed) === rtrim((string) $contents);
        // A workstation override (a development build binding the checkout) is
        // refused for the same reason a workstation run file is.
        $workstation = $contents === null || $stale ? null : ComposeFileInspector::localDevComposeReasonYaml($contents);
        if ($workstation !== null) {
            $logger?->info('Not layering ' . Paths::CLIENT_OVERRIDE_FILENAME . ": {$workstation}, which is a development setup");
            // An empty copy, not none: without one Paths::composeFiles() layers the raw file.
            $fs->filePutContents($copy, self::NOT_LAYERED_OVERRIDE, $chown, '644');

            return;
        }
        if ($contents === null || $stale) {
            if ($fs->fileExists($copy)) {
                $this->dind->system()->exec(['sudo', 'rm', '-f', $copy]);
            }
            if ($stale) {
                $logger?->info(
                    'Not layering the repository\'s ' . Paths::CLIENT_OVERRIDE_FILENAME
                    . ': it was written for the compose file the app config replaces'
                );
            }

            return;
        }

        $read = fn (string $relative): ?string => $this->dind->projectTree()->read(rtrim($projectDir, '/') . '/' . $relative);
        ['yaml' => $contents, 'dropped' => $gone] = ComposeOverride::withoutServices($contents, $droppedServices);
        $hardened = ComposeOverride::harden($contents, $read, $env, $this->dind->userModel()->username, $this->dind->userAppDirPath());
        if ($hardened['yaml'] === null) {
            throw new \InvalidArgumentException(
                'The compose override in this project (' . Paths::CLIENT_OVERRIDE_FILENAME . ') could not be read as YAML.'
            );
        }
        foreach ($hardened['removed'] as $what) {
            $logger?->warn('Removed from ' . Paths::CLIENT_OVERRIDE_FILENAME . ", not allowed in hosting: {$what}");
        }
        foreach ($gone as $name) {
            $logger?->info('Dropped service ' . $name . ' from ' . Paths::CLIENT_OVERRIDE_FILENAME . ': the run file no longer has it');
        }
        $fs->filePutContents($copy, $hardened['yaml'], $chown, '644');
    }

    /**
     * $relative as the checked-out commit has it, or null when git has no
     * such file (or there is no repository, as for an uploaded archive).
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
