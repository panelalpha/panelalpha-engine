<?php

namespace App\System\Project\Dind\Source;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
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
            $this->absolutePath = $home.'/project';
        } else {
            $this->absolutePath = $this->resolveRelative($home, $trimmed);
        }

        $this->pathKey = GitPath::key($this->absolutePath, $home);
    }

    public static function forProjectDir(DindProject $project, string $projectDir): self
    {
        $home = rtrim($project->homeDirPath(), '/');
        $relative = str_starts_with($projectDir, $home.'/')
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
        $logger?->ok(self::clonedMessage($branch ?? $this->readHeadBranch()));
        if ($this->wantsFullHistory($gitRepo)) {
            $logger?->info('Fetching the full history and tags (the recipe sets git: {history: full})');
            $this->rethrowingGitFailure(fn () => $this->fetchFullHistory($gitToken));
        }
        $this->fetchSubmodules($gitRepo, $gitToken);
    }

    private function cloningMessage(string $gitRepo, ?string $branch): string
    {
        $safeRepo = GitUrl::sanitize($gitRepo);

        return $branch !== null
            ? "Cloning repository {$safeRepo} (branch: {$branch})"
            : "Cloning repository {$safeRepo}";
    }

    /** Names the branch the clone landed on, so the deploy history can tell a default-branch create's branch. */
    private static function clonedMessage(?string $branch): string
    {
        return $branch !== null ? "Repository cloned (branch: {$branch})" : 'Repository cloned';
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

    /**
     * The submodules the checkout records, or the ones the app config names. One that
     * cannot be fetched fails the deploy: a build without it fails later, on a missing
     * file that says nothing about the submodule. $keepPrevious on a redeploy of a checkout
     * that deployed before: its submodules keep the checkout they had, with a warning.
     */
    public function fetchSubmodules(string $gitRepo, ?string $gitToken, bool $keepPrevious = false): void
    {
        if (! is_file($this->absolutePath.'/.gitmodules')) {
            return;
        }
        $logger = $this->project->shell()->logger();
        $paths = $this->wantedSubmodules($gitRepo);
        if ($paths !== null && $paths !== []) {
            $logger?->info('Fetching only the submodules the app config names: '.implode(', ', $paths));
        }

        try {
            if (! $this->initSubmodules($gitToken, $paths)) {
                return;
            }
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->submodulesNotFetched($e, $keepPrevious);

            return;
        }

        $logger?->ok('Submodules fetched');
    }

    /** @throws \RuntimeException unless $keepPrevious */
    private function submodulesNotFetched(\Throwable $e, bool $keepPrevious): void
    {
        $failure = DeployFailureExplainer::SUBMODULE_FAILURE.': '.GitUrl::sanitize($e->getMessage());
        if (! $keepPrevious) {
            throw new \RuntimeException($failure, 0, $e);
        }
        $this->project->shell()->logger()?->warn($failure.'. The deploy goes on with the submodule checkout the previous deploy had.');
    }

    /**
     * `git: {submodules: [...]}`; null for every one. A malformed app config reads as
     * every one, and is reported by the preparation that follows.
     *
     * @return ?list<string>
     */
    private function wantedSubmodules(string $gitRepo): ?array
    {
        try {
            return $this->project->appConfig($gitRepo)?->gitSubmodules();
        } catch (ManifestException) {
            return null;
        }
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

        return $home.'/'.ltrim($trimmed, '/');
    }
}
