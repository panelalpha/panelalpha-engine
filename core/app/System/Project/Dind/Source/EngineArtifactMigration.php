<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Paths;
use Closure;

/**
 * One-off cleanup into the ADR-0001 layout: a reserved run file, no stashes, the client's files back as git has
 * them. Never restarts a container: the compose project name does not depend on the file names it moves.
 */
final class EngineArtifactMigration
{
    /**
     * @param  Closure(string $from, string $to): void  $moveFile  same-owner rename
     * @param  Closure(string $from, string $to): void  $copyFile  new file, ownership follows $from
     * @param  Closure(string $path): void  $deleteFile
     */
    public function __construct(
        private GitRepository $git,
        private string $projectDir,
        private ?string $strategy,
        private ?string $appConfigComposeMode,
        private Closure $moveFile,
        private Closure $copyFile,
        private Closure $deleteFile,
    ) {}

    /**
     * composeMode() answers `replace` even for an app config without a compose file: only one with a file
     * has a mode.
     */
    public static function forProject(DindProject $dind): self
    {
        $system = $dind->system();
        $appConfig = $dind->appConfig($dind->userModel()->getGitRepo());
        $files = Privileged::accountFiles(...$dind->userModel()->linuxIds());

        return new self(
            new GitRepository($dind),
            $dind->userAppDirPath(),
            $dind->userModel()->getDeployStrategy(),
            $appConfig?->compose() !== null ? $appConfig->composeMode() : null,
            static function (string $from, string $to) use ($system, $files): void {
                $system->exec($files->moveOverwriting($from, $to));
            },
            static function (string $from, string $to) use ($system, $files): void {
                $system->exec($files->replaceWithPreservedCopy($from, $to));
            },
            static function (string $path) use ($system, $files): void {
                $system->exec($files->removeIfExists($path));
            },
        );
    }

    /**
     * @return list<string> a report line per file moved, restored or left,
     *                      for the caller to print
     */
    public function migrate(): array
    {
        return [
            ...$this->migrateRecipeRunFile(),
            ...$this->migrateStashes(),
            ...$this->migrateNormalizedClientCompose(),
            ...$this->migrateAppConfigOverride(),
            ...$this->migrateComposerPin(),
        ];
    }

    private function path(string $name): string
    {
        return rtrim($this->projectDir, '/').'/'.$name;
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen(rtrim($this->projectDir, '/'))), '/');
    }

    /**
     * A recipe's generated compose the previous engine left under a name the client might own
     * (`docker-compose.yml`, …), moved to the run file.
     *
     * @return list<string>
     */
    private function migrateRecipeRunFile(): array
    {
        $run = $this->path(EngineArtifacts::RUN_COMPOSE);
        if (is_file($run)) {
            return [];
        }

        foreach (Paths::composeFileCandidates() as $candidate) {
            $path = $this->path($candidate);
            if (is_file($path) && ComposeFileInspector::isGeneratedBootstrapCompose($path)) {
                ($this->moveFile)($path, $run);

                return ['moved '.$candidate.' to '.EngineArtifacts::RUN_COMPOSE.' (recipe-generated compose)'];
            }
        }

        return [];
    }

    /**
     * `*.panelalpha-local` stashes of a higher-priority compose name: discarded under git, which is trusted
     * to hold what should run; without git both are kept for a human to sort.
     *
     * @return list<string>
     */
    private function migrateStashes(): array
    {
        $report = [];
        $stashes = glob($this->path('*').EngineArtifacts::LEGACY_STASH_SUFFIX) ?: [];
        foreach ($stashes as $stash) {
            $original = substr($stash, 0, -strlen(EngineArtifacts::LEGACY_STASH_SUFFIX));
            $name = basename($original);

            if (! is_file($original)) {
                ($this->moveFile)($stash, $original);
                $report[] = "restored {$name} from its stash (nothing had taken its place)";

                continue;
            }

            if ($this->git->hasRepository()) {
                ($this->deleteFile)($stash);
                $report[] = "deleted the stash of {$name}: {$name} is already there";
            } else {
                $report[] = "left the stash of {$name}: no git checkout to trust, and {$name} already exists — check by hand";
            }
        }

        return $report;
    }

    /**
     * The compose-strategy/PAEMD run file: a copy of what the project's own file has become, with the
     * original restored from git when in-place hardening is all that differs from HEAD.
     *
     * @return list<string>
     */
    private function migrateNormalizedClientCompose(): array
    {
        $run = $this->path(EngineArtifacts::RUN_COMPOSE);
        if (is_file($run) || ($this->strategy !== Strategies::COMPOSE && $this->strategy !== Strategies::PAEMD)) {
            return [];
        }

        $source = $this->firstClientComposeFile();
        if ($source === null) {
            return [];
        }

        ($this->copyFile)($source, $run);
        $report = ['copied '.basename($source).' to '.EngineArtifacts::RUN_COMPOSE.' (the version already running)'];

        return [...$report, ...$this->keepAppConfigCompose($source), ...$this->restoreHardenedCompose($source)];
    }

    private function firstClientComposeFile(): ?string
    {
        $source = null;
        foreach (Paths::composeFileCandidates() as $candidate) {
            $path = $this->path($candidate);
            if (is_file($path)) {
                $source = $path;
                break;
            }
        }

        return $source;
    }

    /**
     * A `replace`-mode app config wrote its compose file over the client's: kept under its reserved name
     * before git restores the client's, or the next `up` would regenerate the run file from the client's.
     *
     * @return list<string>
     */
    private function keepAppConfigCompose(string $source): array
    {
        $report = [];
        $appConfigCompose = $this->path(EngineArtifacts::APP_CONFIG_COMPOSE);
        if ($this->appConfigComposeMode === AppConfig::COMPOSE_REPLACE && ! is_file($appConfigCompose)) {
            ($this->copyFile)($source, $appConfigCompose);
            $report[] = 'copied '.basename($source).' to '.EngineArtifacts::APP_CONFIG_COMPOSE
                ." (this account's app config replaces the compose file)";
        }

        return $report;
    }

    /** @return list<string> */
    private function restoreHardenedCompose(string $source): array
    {
        $report = [];
        if ($this->git->hasRepository()) {
            $relative = $this->relative($source);
            if ($this->git->fileDiffersFromHead($relative)) {
                $this->git->restoreFromHead($relative);
                $report[] = 'restored '.basename($source).' from git (it had been hardened in place)';
            }
        }

        return $report;
    }

    /**
     * An `override`-mode app config's `docker-compose.override.yml` under the client's name, moved to the
     * engine's reserved override; `replace` mode is {@see migrateNormalizedClientCompose()}'s.
     *
     * @return list<string>
     */
    private function migrateAppConfigOverride(): array
    {
        $legacy = $this->path(Paths::CLIENT_OVERRIDE_FILENAME);
        if ($this->appConfigComposeMode !== AppConfig::COMPOSE_OVERRIDE || ! is_file($legacy)) {
            return [];
        }

        $target = $this->path(EngineArtifacts::RUN_COMPOSE_OVERRIDE);
        ($this->moveFile)($legacy, $target);

        return [
            'moved '.Paths::CLIENT_OVERRIDE_FILENAME.' to '.EngineArtifacts::RUN_COMPOSE_OVERRIDE
                ." (this account's app config layers an override)",
        ];
    }

    /**
     * `composer.json` restored from HEAD only when the previous engine's platform pin is all that differs:
     * anything else is the client's own edit.
     *
     * @return list<string>
     */
    private function migrateComposerPin(): array
    {
        $composerJson = $this->path('composer.json');
        if (! is_file($composerJson) || ! $this->git->hasRepository()) {
            return [];
        }

        $relative = $this->relative($composerJson);
        if (! $this->git->fileDiffersFromHead($relative)) {
            return [];
        }

        $head = $this->git->readFromHead($relative);
        $current = @file_get_contents($composerJson);
        if ($head === null || $current === false || ! self::differsOnlyByPlatformPin($head, $current)) {
            return [];
        }

        $this->git->restoreFromHead($relative);

        return ['restored composer.json from git (only the platform pin had changed)'];
    }

    private static function differsOnlyByPlatformPin(string $head, string $current): bool
    {
        $headDecoded = json_decode($head, true);
        $currentDecoded = json_decode($current, true);
        if (! is_array($headDecoded) || ! is_array($currentDecoded)) {
            return false;
        }

        unset($currentDecoded['config']['platform']['php']);
        if (($currentDecoded['config']['platform'] ?? null) === []) {
            unset($currentDecoded['config']['platform']);
        }
        if (($currentDecoded['config'] ?? null) === []) {
            unset($currentDecoded['config']);
        }

        return $headDecoded == $currentDecoded;
    }
}
