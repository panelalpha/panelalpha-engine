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
use Illuminate\Support\Facades\Log;

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
        $safeRepo = GitUrl::sanitize($gitRepo);
        $branch = $user->getGitBranch();
        if ($branch !== null) {
            $logger?->info("Cloning repository {$safeRepo} (branch: {$branch})");
        } else {
            $logger?->info("Cloning repository {$safeRepo}");
        }

        try {
            if ($beforeReplacing === null) {
                $this->clone($gitRepo, $branch, $gitToken);
            } else {
                $this->cloneThenReplace($gitRepo, $branch, $gitToken, $gitProjectDir, $beforeReplacing);
            }
            $this->adoptCheckout($gitRepo, $branch, $gitToken);
        } catch (GitException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $logger?->ok('Repository cloned');
        if ($this->wantsFullHistory($gitRepo)) {
            $logger?->info('Fetching the full history and tags (the recipe sets git: {history: full})');
            try {
                $this->fetchFullHistory($gitToken);
            } catch (GitException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        }
        $this->fetchSubmodules($gitToken);
        $this->allowUntrustedGitDirectory();
    }

    private function cloneThenReplace(
        string $gitRepo,
        ?string $branch,
        ?string $gitToken,
        string $gitProjectDir,
        callable $beforeReplacing,
    ): void {
        $stagingDir = $this->tree()->stagingDirPath();
        $this->tree()->removeStaging($stagingDir);
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

    /** Read after the clone, so a repository's own `.panelalpha/` can ask too. */
    private function wantsFullHistory(string $gitRepo): bool
    {
        try {
            return $this->project->appConfig($gitRepo)?->fullGitHistory() ?? false;
        } catch (ManifestException) {
            // A malformed app config is reported by the preparation that follows.
            return false;
        }
    }

    public function allowUntrustedGitDirectory(): void
    {
        try {
            $this->configureSafeDirectory();
        } catch (\Exception $e) {
            Log::warning('Could not set git safe.directory', ['error' => $e->getMessage()]);
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
    protected function fetchAndSyncFreshInit(string $branch, ?string $token): void
    {
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        return $this->shell()->execAsUser($cmd, [], $timeout);
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
