<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;

/**
 * Paths for the inner ~/project tree inside a DinD account container.
 */
final class Paths
{
    /**
     * Compose's own name for a layer applied over the base file, when the
     * client ships one themselves. Layered only for the compose/PAEMD
     * strategies (D8) — an engine-generated recipe never reads a file this
     * name, because a recipe author never intended it to sit under one.
     */
    public const CLIENT_OVERRIDE_FILENAME = 'docker-compose.override.yml';

    /** @var list<string> */
    private const COMPOSE_FILE_CANDIDATES = ComposeFileInspector::COMPOSE_FILE_CANDIDATES;

    public function __construct(
        private DindProject $project,
    ) {
    }

    public function appDir(): string
    {
        return $this->project->homeDirPath() . '/project';
    }

    /** The compose file the engine runs — ADR-0001: never a name the client may own. */
    public function composeFile(): string
    {
        return $this->appDir() . '/' . EngineArtifacts::RUN_COMPOSE;
    }

    /**
     * The compose file the project brings: an app config's `replace`-mode
     * file if one is present (read ahead of the repository's own, per D2),
     * else the project's own by conventional name, or null when it ships
     * none.
     */
    public function existingComposeFile(): ?string
    {
        $fs = $this->project->system()->filesystem();
        $appConfigCompose = $this->appDir() . '/' . EngineArtifacts::APP_CONFIG_COMPOSE;
        if ($fs->fileExists($appConfigCompose)) {
            return $appConfigCompose;
        }
        foreach (self::COMPOSE_FILE_CANDIDATES as $name) {
            $path = $this->appDir() . '/' . $name;
            if ($fs->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The compose file `compose up` runs. Always {@see composeFile()} — every
     * strategy writes its result there — kept as its own method because
     * callers ask "which file do I run" and "which file did the project
     * bring" as two different questions.
     */
    public function composeFileToRun(): string
    {
        return $this->composeFile();
    }

    /** The engine's own override layer — an app config's `override`-mode file. */
    public function composeOverrideFile(): string
    {
        return $this->appDir() . '/' . EngineArtifacts::RUN_COMPOSE_OVERRIDE;
    }

    /**
     * The compose file whose ports matter for detection: the hardened run
     * file when it exists, otherwise the project's own. Unlike
     * {@see composeFileToRun()}, this does not depend on the deploy
     * strategy — every caller that only cares about published ports
     * (proxy rules, welcome bootstrap, staging clone) wants this file
     * whether or not a strategy has been decided yet, and before a first
     * deploy has produced a run file at all.
     */
    public function composeFileForPorts(): string
    {
        $run = $this->composeFile();
        if ($this->project->system()->filesystem()->fileExists($run)) {
            return $run;
        }

        return $this->existingComposeFile() ?? $run;
    }

    /**
     * The compose filenames docker compose recognises, in the priority
     * order it searches them. The one place this list is spelled out;
     * every reader of "is this an inner compose file" goes through here.
     *
     * @return list<string>
     */
    public static function composeFileCandidates(): array
    {
        return self::COMPOSE_FILE_CANDIDATES;
    }

    /**
     * Whether $path is a compose file the engine itself owns — one of the
     * reserved run-file names, or (for backward compatibility with an
     * account not yet migrated) an unnamed file the engine generated.
     */
    public static function isEngineComposeFile(string $path): bool
    {
        if (in_array(basename($path), [
            EngineArtifacts::RUN_COMPOSE,
            EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            EngineArtifacts::RUN_CLIENT_OVERRIDE,
            EngineArtifacts::APP_CONFIG_COMPOSE,
        ], true)) {
            return true;
        }

        return ComposeFileInspector::isGeneratedBootstrapCompose($path);
    }

    /**
     * The files docker compose is invoked with, in layering order (D8): the
     * run file, the client's own override when the strategy is compose or
     * PAEMD (a recipe's generated stack is never one the client meant to
     * extend), then the engine's own override from an app config.
     *
     * @return list<string>
     */
    public function composeFiles(): array
    {
        $files = [$this->composeFileToRun()];
        $fs = $this->project->system()->filesystem();

        if ($this->layersClientOverride()) {
            $clientOverride = $this->clientOverrideIn($this->appDir());
            if ($clientOverride !== null && !in_array($clientOverride, $files, true)) {
                $files[] = $clientOverride;
            }
        }

        $engineOverride = $this->composeOverrideFile();
        if (!in_array($engineOverride, $files, true) && $fs->fileExists($engineOverride)) {
            $files[] = $engineOverride;
        }

        return $files;
    }

    /**
     * The client's override as the engine layers it: the hardened copy the
     * last deploy wrote ({@see EngineArtifacts::RUN_CLIENT_OVERRIDE}), else
     * the file itself for an account not redeployed since that copy existed.
     */
    private function clientOverrideIn(string $appDir): ?string
    {
        $fs = $this->project->system()->filesystem();
        // Under an app config's replacing compose file only the copy counts:
        // the deploy leaves none when the override is the repository's own.
        $names = $fs->fileExists($appDir . '/' . EngineArtifacts::APP_CONFIG_COMPOSE)
            ? [EngineArtifacts::RUN_CLIENT_OVERRIDE]
            : [EngineArtifacts::RUN_CLIENT_OVERRIDE, self::CLIENT_OVERRIDE_FILENAME];
        foreach ($names as $name) {
            if ($fs->fileExists($appDir . '/' . $name)) {
                return $appDir . '/' . $name;
            }
        }

        return null;
    }

    /**
     * Whether the client's own `docker-compose.override.yml` is layered (D8):
     * only when the run file is derived from the client's compose file.
     */
    private function layersClientOverride(): bool
    {
        $strategy = $this->project->userModel()->getDeployStrategy();

        return $strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD;
    }

    /**
     * @param list<string> $rest
     * @return list<string>
     */
    public function composeCommand(array $rest): array
    {
        $command = $this->composePrefix($this->appDir());
        foreach ($this->composeFiles() as $file) {
            $command[] = '-f';
            $command[] = $file;
        }

        return array_merge($command, $rest);
    }

    /**
     * `docker exec` sets no PWD, so a `${PWD}/data` bind interpolated to
     * `/data`; compose run by hand from the project has it as the project.
     *
     * @return list<string>
     */
    private function composePrefix(string $appDir): array
    {
        $command = ['env', 'PWD=' . $appDir, 'docker', 'compose', '--project-directory', $appDir];
        foreach ($this->envFiles($appDir) as $file) {
            $command[] = '--env-file';
            $command[] = $file;
        }

        return $command;
    }

    /**
     * The files compose interpolates from, when the engine keeps values in
     * `.env.panelalpha` (generated secrets, a tracked .env's overrides). Naming
     * any env file replaces the default `.env`, so it is named too. Empty when
     * there is no `.env.panelalpha`: compose's own default then applies.
     *
     * @return list<string>
     */
    public function envFiles(?string $appDir = null): array
    {
        $appDir = rtrim($appDir ?? $this->appDir(), '/');
        $fs = $this->project->system()->filesystem();
        if (!$fs->fileExists($appDir . '/' . EngineArtifacts::ENV_OVERRIDES)) {
            return [];
        }

        $files = [];
        foreach (['.env', EngineArtifacts::ENV_OVERRIDES] as $name) {
            // Compose refuses a named env file that does not exist.
            if ($fs->fileExists($appDir . '/' . $name)) {
                $files[] = $appDir . '/' . $name;
            }
        }

        return $files;
    }

    /**
     * {@see composeCommand()} as environment, for scripts that call a bare
     * `docker compose`: with the first file in appDir(), compose derives the
     * same project directory and name the deploy used.
     *
     * @return array<string, string> COMPOSE_FILE, COMPOSE_PATH_SEPARATOR and, with {@see envFiles()}, COMPOSE_ENV_FILES
     */
    public function composeEnv(): array
    {
        $env = [
            'COMPOSE_FILE' => implode(':', $this->composeFiles()),
            'COMPOSE_PATH_SEPARATOR' => ':',
        ];
        $envFiles = $this->envFiles();
        if ($envFiles !== []) {
            $env['COMPOSE_ENV_FILES'] = implode(',', $envFiles);
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    public function composeCommandForDirectory(string $appDir): array
    {
        $appDir = rtrim($appDir, '/');
        $files = $this->composeFilesIn($appDir);
        $command = $this->composePrefix($appDir);
        foreach ($files as $file) {
            $command[] = '-f';
            $command[] = $file;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private function composeFilesIn(string $appDir): array
    {
        $fs = $this->project->system()->filesystem();
        $run = $appDir . '/' . EngineArtifacts::RUN_COMPOSE;
        $primary = $fs->fileExists($run) ? $run : ($this->existingComposeFileIn($appDir) ?? $run);
        $files = [$primary];

        // Same layering as composeFiles() (D8): the client's override only
        // under the compose strategies, then the app config's override.
        if ($this->layersClientOverride()) {
            $clientOverride = $this->clientOverrideIn($appDir);
            if ($clientOverride !== null && !in_array($clientOverride, $files, true)) {
                $files[] = $clientOverride;
            }
        }

        $engineOverride = $appDir . '/' . EngineArtifacts::RUN_COMPOSE_OVERRIDE;
        if (!in_array($engineOverride, $files, true) && $fs->fileExists($engineOverride)) {
            $files[] = $engineOverride;
        }

        return $files;
    }

    private function existingComposeFileIn(string $appDir): ?string
    {
        $fs = $this->project->system()->filesystem();
        foreach (self::COMPOSE_FILE_CANDIDATES as $candidate) {
            $path = $appDir . '/' . $candidate;
            if ($fs->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }
}
