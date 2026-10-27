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

    /** @var ?array{declared: list<string>, checkedOut: list<string>} the submodules of the checkout a re-clone replaces */
    private ?array $replacedCheckout = null;

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
        $this->fetchSubmodules($gitRepo, $gitToken, $beforeReplacing !== null && $user->deploysAsUpgrade());
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

        $this->replacedCheckout = $this->submodulesOfHead();
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
     * file that says nothing about the submodule. $keepPrevious on a redeploy of a project
     * that deployed before: a submodule the deployed commit already declared keeps whatever
     * checkout it had, with a warning.
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
            $this->submodulesNotFetched($e, $keepPrevious, $paths);

            return;
        }

        $logger?->ok('Submodules fetched');
    }

    /**
     * Only a submodule the deployed commit already declared may stay as it was. One new in this
     * commit fails the deploy as on a first deploy, and so do one a re-clone would drop and a
     * checkout git cannot read.
     *
     * @param  ?list<string>  $paths
     *
     * @throws \RuntimeException unless $keepPrevious and every submodule left behind was declared before
     */
    private function submodulesNotFetched(\Throwable $e, bool $keepPrevious, ?array $paths): void
    {
        $failure = DeployFailureExplainer::SUBMODULE_FAILURE.': '.GitUrl::sanitize($e->getMessage());
        $missing = $keepPrevious ? $this->submodulesWithoutCheckout($paths) : null;
        if ($missing === null) {
            throw new \RuntimeException($failure, 0, $e);
        }
        $previous = $this->previousSubmodules();
        $lost = array_values(array_intersect($missing, $previous['checkedOut']));
        if ($lost !== [] || array_diff($missing, $previous['declared']) !== []) {
            throw new \RuntimeException($failure.self::lostByReclone($lost), 0, $e);
        }
        $this->project->shell()->logger()?->warn($failure.'. '.self::goesOnWithout($missing));
    }

    /** @param list<string> $lost */
    private static function lostByReclone(array $lost): string
    {
        return $lost === []
            ? ''
            : '. A rebuild clones the repository afresh, so the checkout of '.implode(', ', $lost)
                .' the previous deploy had cannot be kept';
    }

    /** @param list<string> $missing */
    private static function goesOnWithout(array $missing): string
    {
        return $missing === []
            ? 'The deploy goes on with the submodule checkout the previous deploy had.'
            : 'The deploy goes on without '.implode(', ', $missing).': there is no earlier checkout of it to keep.';
    }

    /**
     * The submodules HEAD declares that have no files, or null when git could not tell.
     *
     * @param  ?list<string>  $paths
     * @return ?list<string>
     */
    private function submodulesWithoutCheckout(?array $paths): ?array
    {
        $declared = $this->declaredSubmodules('HEAD', $paths);
        if ($declared === null) {
            return null;
        }
        $checkedOut = $this->checkedOutSubmodules($declared);

        return $checkedOut === null ? null : array_values(array_diff($declared, $checkedOut));
    }

    /**
     * What the checkout had before this deploy: the one a re-clone replaced, else the commit
     * the previous deploy recorded, whose submodules a pull leaves in place.
     *
     * @return array{declared: list<string>, checkedOut: list<string>}
     */
    private function previousSubmodules(): array
    {
        if ($this->replacedCheckout !== null) {
            return $this->replacedCheckout;
        }
        $commit = $this->user()->getGitCommit();

        return ['declared' => $commit === null ? [] : ($this->declaredSubmodules($commit, null) ?? []), 'checkedOut' => []];
    }

    /** @return array{declared: list<string>, checkedOut: list<string>} */
    private function submodulesOfHead(): array
    {
        $declared = $this->declaredSubmodules('HEAD', null) ?? [];

        return ['declared' => $declared, 'checkedOut' => $this->checkedOutSubmodules($declared) ?? $declared];
    }

    /**
     * The submodule paths `.gitmodules` declares at $rev, of $paths when the app config names them;
     * null when git could not read them.
     *
     * @param  ?list<string>  $paths
     * @return ?list<string>
     */
    private function declaredSubmodules(string $rev, ?array $paths): ?array
    {
        try {
            $out = $this->quietly(fn (): string => $this->git(['config', '--blob', $rev.':.gitmodules', '-z', '--get-regexp', '^submodule\..*\.path$']));
        } catch (GitException) {
            return null;
        }
        $declared = [];
        foreach (explode("\0", $out) as $entry) {
            $newline = strpos($entry, "\n");
            if ($newline !== false) {
                $declared[] = substr($entry, $newline + 1);
            }
        }

        return array_values(array_unique($paths === null ? $declared : array_intersect($declared, $paths)));
    }

    /**
     * The submodules of $paths with files checked out, or null when git could not tell. An
     * initialised but empty submodule lists nothing, an uninitialised one only its own path.
     *
     * @param  list<string>  $paths
     * @return ?list<string>
     */
    private function checkedOutSubmodules(array $paths): ?array
    {
        if ($paths === []) {
            return [];
        }
        try {
            $files = explode("\0", $this->quietly(fn (): string => $this->git(['--literal-pathspecs', 'ls-files', '--recurse-submodules', '-z', '--', ...$paths])));
        } catch (GitException) {
            return null;
        }

        return array_values(array_filter($paths, static fn (string $path): bool => self::anyUnder($files, $path)));
    }

    /** @param list<string> $files */
    private static function anyUnder(array $files, string $path): bool
    {
        foreach ($files as $file) {
            if (str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
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

    protected function runsOnHost(): bool
    {
        return false;
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
