<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\WorkTree;

/**
 * The deploy ingest's view of ~/project. Everything a work tree can do lives in
 * {@see WorkTree}; this adds the one-shot clone of the account's configured
 * remote and runs every command through the DinD shell.
 */
class GitRepository extends WorkTree
{
    private ?ProjectTree $tree = null;
    private ?ShellOperations $shell = null;

    public function __construct(
        private DindProject $project,
        ?string $relativePath = null,
    ) {
        $home = rtrim($project->homeDirPath(), '/');
        $trimmed = trim((string) $relativePath);

        if ($trimmed === '') {
            $this->absolutePath = $home . '/project';
        } else {
            $this->absolutePath = $this->resolveRelative($home, $trimmed);
        }

        $this->pathKey = GitPath::key($this->absolutePath, $home);
    }

    public static function forProjectDir(DindProject $project, string $projectDir): self
    {
        $home = rtrim($project->homeDirPath(), '/');
        $relative = str_starts_with($projectDir, $home . '/')
            ? substr($projectDir, strlen($home) + 1)
            : 'project';

        return new self($project, $relative);
    }

    /**
     * Clone the account's configured git remote into ~/project (no deploy preparation).
     *
     * With $beforeReplacing the clone lands in a staging directory first, and
     * ~/project is only emptied (after $beforeReplacing ran) once it succeeded,
     * so a failed clone leaves the running app and its files alone.
     */
    public function cloneConfiguredRepository(?callable $beforeReplacing = null): void
    {
        $user = $this->project->userModel();
        $gitRepo = $user->getGitRepoOrFail();
        $gitProjectDir = $this->tree()->appDirPath();
        $logger = $this->project->shell()->logger();

        if ($beforeReplacing === null) {
            $this->emptyDirectoryFor($gitProjectDir);
        }

        $gitToken = $user->getGitToken();
        $branch = $user->getGitBranch();
        $logger?->info($this->cloningMessage($gitRepo, $branch));

        $this->cloneAndAdopt($gitRepo, $branch, $gitToken, $gitProjectDir, $beforeReplacing);
        $logger?->ok('Repository cloned');
        if ($this->wantsFullHistory($gitRepo)) {
            $logger?->info('Fetching the full history and tags (the recipe sets git: {history: full})');
            $this->rethrowingGitFailure(fn () => $this->fetchFullHistory($gitToken));
        }
        $this->fetchSubmodules($gitToken);
    }

    private function cloningMessage(string $gitRepo, ?string $branch): string
    {
        $safeRepo = GitUrl::sanitize($gitRepo);

        return $branch !== null
            ? "Cloning repository {$safeRepo} (branch: {$branch})"
            : "Cloning repository {$safeRepo}";
    }

    private function cloneAndAdopt(
        string $gitRepo,
        ?string $branch,
        ?string $gitToken,
        string $gitProjectDir,
        ?callable $beforeReplacing,
    ): void {
        $this->rethrowingGitFailure(function () use ($gitRepo, $branch, $gitToken, $gitProjectDir, $beforeReplacing): void {
            if ($beforeReplacing === null) {
                $this->clone($gitRepo, $branch, $gitToken);
            } else {
                $this->cloneThenReplace($gitRepo, $branch, $gitToken, $gitProjectDir, $beforeReplacing);
            }
            $this->adoptCheckout($gitRepo, $branch, $gitToken);
        });
    }

    /**
     * Runs $operation with a git failure turned into a RuntimeException carrying its message.
     */
    private function rethrowingGitFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (GitException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function cloneThenReplace(
        string $gitRepo,
        ?string $branch,
        ?string $gitToken,
        string $gitProjectDir,
        callable $beforeReplacing,
    ): void {
        $this->tree()->removeAbandonedStaging();
        $stagingDir = $this->tree()->stagingDirPath();
        $this->emptyDirectoryFor($stagingDir, clear: false);

        try {
            $this->clone($gitRepo, $branch, $gitToken, $stagingDir);
        } catch (\Throwable $e) {
            $this->tree()->removeStaging($stagingDir);
            throw $e;
        }

        $beforeReplacing();
        $this->emptyDirectoryFor($gitProjectDir);
        $this->tree()->moveStagingInto($stagingDir, $gitProjectDir);
    }

    private function emptyDirectoryFor(string $dir, bool $clear = true): void
    {
        $system = $this->project->system();
        $chown = $this->project->userModel()->getChownString();

        $system->exec(['sudo', 'mkdir', '-p', $dir]);
        if ($chown) {
            $system->exec(['sudo', 'chown', $chown, $dir]);
        }
        if ($clear) {
            $this->tree()->clearContents($dir);
        }
    }

    /**
     * Read after the clone, so a repository's own `.panelalpha/` can ask too. A malformed
     * app config reads as no, and is reported by the preparation that follows.
     */
    private function wantsFullHistory(string $gitRepo): bool
    {
        try {
            return $this->project->appConfig($gitRepo)?->fullGitHistory() ?? false;
        } catch (ManifestException) {
            return false;
        }
    }

    private function fetchSubmodules(?string $gitToken): void
    {
        $logger = $this->project->shell()->logger();

        try {
            if (!$this->initSubmodules($gitToken)) {
                return;
            }
        } catch (\Throwable $e) {
            $logger?->warn(
                'Some submodules could not be fetched, so parts of this repository are missing: '
                . GitUrl::sanitize($e->getMessage())
            );

            return;
        }

        $logger?->ok('Submodules fetched');
    }

    protected function user(): ModelsUser
    {
        return $this->project->userModel();
    }

    protected function system(): EngineSystem
    {
        return $this->project->system();
    }

    protected function homeDirPath(): string
    {
        return $this->project->homeDirPath();
    }

    /** A fresh `git init` stays empty here until a pull: this class never synced it on connect. */
    protected function fetchAndSyncFreshInit(string $branch, ?string $token): ?string
    {
        return null;
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        return $this->shell()->execAsUser($cmd, [], $timeout);
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        return $this->shell()->execAsUserQuiet($cmd, [], $timeout);
    }

    private function tree(): ProjectTree
    {
        return $this->tree ??= new ProjectTree($this->project);
    }

    private function shell(): ShellOperations
    {
        return $this->shell ??= new ShellOperations($this->project);
    }

    private function resolveRelative(string $home, string $trimmed): string
    {
        if (str_starts_with($trimmed, '/')) {
            throw new \InvalidArgumentException('Project-relative paths only.');
        }

        return $home . '/' . ltrim($trimmed, '/');
    }
}
