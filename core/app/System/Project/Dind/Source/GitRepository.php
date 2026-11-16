<?php

namespace App\System\Project\Dind\Source;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployStartLine;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\WorkTree;

/**
 * The deploy ingest's view of ~/project: {@see WorkTree} plus the one-shot clone of the account's configured
 * remote, with every command run through the DinD shell.
 */
class GitRepository extends WorkTree
{
    private ?ProjectTree $tree = null;

    private ?ShellOperations $shell = null;

    /** @var ?list<string> the submodules with files in the checkout a re-clone replaces */
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
     * Clone the account's configured remote into ~/project (no deploy preparation). With $beforeReplacing it lands in
     * staging, and ~/project is emptied (after $beforeReplacing) only once it worked: a failed clone leaves the app.
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
        $logger?->ok($this->clonedMessage($branch));
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

    /**
     * Names the branch and the commit the clone landed on: a create or a rebuild logs its
     * start before either is known, and the deploy history reads both from here.
     */
    private function clonedMessage(?string $branch): string
    {
        return DeployStartLine::cloned(
            $branch ?? $this->readHeadBranch(),
            $this->quietly(fn (): ?string => $this->readHeadCommit()),
        );
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

        $this->replacedCheckout = $this->checkedOutSubmodulesOfHead();
        $beforeReplacing();
        $this->emptyDirectoryFor($gitProjectDir);
        $this->tree()->moveStagingInto($stagingDir, $gitProjectDir);
    }

    private function emptyDirectoryFor(string $dir, bool $clear = true): void
    {
        $system = $this->project->system();
        $chown = $this->project->userModel()->getChownString();

        $system->exec(Privileged::files()->makeDirectories($dir));
        if ($chown) {
            $system->exec(Privileged::files()->changeOwner($chown, $dir));
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
     * The submodules the checkout records or the app config names. One not fetched is left out with a warning naming it,
     * unless a rebuild's re-clone would drop the checkout of it the running app has.
     */
    public function fetchSubmodules(string $gitRepo, ?string $gitToken): void
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
            $this->fetchEachSubmodule($gitToken, $paths, $e);

            return;
        }

        $logger?->ok('Submodules fetched');
    }

    /**
     * Git gives up on the remaining submodules once one fails twice, leaving those it cloned unchecked-out, so each
     * is fetched and checked out on its own, and the ones that still fail are reported.
     *
     * @param  ?list<string>  $paths
     */
    private function fetchEachSubmodule(?string $gitToken, ?array $paths, \Throwable $failure): void
    {
        $declared = $this->declaredSubmodules('HEAD', $paths);
        if ($declared === null || $declared === []) {
            $this->submodulesNotFetched($failure, $paths);

            return;
        }
        $failures = array_filter(array_map(fn (string $path): ?\Throwable => $this->fetchOneSubmodule($gitToken, $path), $declared));
        if ($failures === []) {
            $this->project->shell()->logger()?->ok('Submodules fetched');

            return;
        }
        $this->submodulesNotFetched(reset($failures), $paths);
    }

    private function fetchOneSubmodule(?string $gitToken, string $path): ?\Throwable
    {
        try {
            $this->initSubmodules($gitToken, [$path], checkout: true);

            return null;
        } catch (\Throwable $e) {
            if ($e instanceof DeployCancelledException) {
                throw $e;
            }

            return $e;
        }
    }

    /**
     * @param  ?list<string>  $paths
     *
     * @throws \RuntimeException when a rebuild would drop a submodule checkout the running app has
     */
    private function submodulesNotFetched(\Throwable $e, ?array $paths): void
    {
        $failure = DeployFailureExplainer::SUBMODULE_FAILURE.': '.self::gitReason(GitUrl::sanitize($e->getMessage()));
        $missing = $this->submodulesWithoutCheckout($paths);
        $lost = $this->droppedByReclone($missing);
        if ($lost !== []) {
            throw new \RuntimeException($failure.self::lostByReclone($lost), 0, $e);
        }
        $this->project->shell()->logger()?->warn($failure.'. '.self::goesOnWithout($missing));
    }

    /**
     * The submodules the running app has checked out that this re-clone came without.
     *
     * @param  ?list<string>  $missing
     * @return list<string>
     */
    private function droppedByReclone(?array $missing): array
    {
        if ($this->replacedCheckout === null || $missing === null || ! $this->user()->deploysAsUpgrade()) {
            return [];
        }

        return array_values(array_intersect($missing, $this->replacedCheckout));
    }

    /** The line git explained the failure in (its own output is already in the deploy log): `fatal:` first, then `error:`. */
    private static function gitReason(string $output): string
    {
        foreach (['fatal', 'error'] as $prefix) {
            if (preg_match('/^\s*'.$prefix.': ([^\n]+)/m', $output, $m) === 1) {
                return rtrim(trim($m[1]), '.');
            }
        }

        return rtrim(trim(strtok($output, "\n") ?: $output), '.');
    }

    /** @param list<string> $lost */
    private static function lostByReclone(array $lost): string
    {
        return '. A rebuild clones the repository afresh, so the checkout of '.implode(', ', $lost)
            .' the running app has cannot be kept';
    }

    /** @param ?list<string> $missing */
    private static function goesOnWithout(?array $missing): string
    {
        return match (true) {
            $missing === null => 'The deploy goes on without the submodules that did not come down.',
            $missing === [] => 'The deploy goes on with the submodule checkout it has.',
            default => 'The deploy goes on without '.implode(', ', $missing).'.',
        };
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

    /** @return list<string> */
    private function checkedOutSubmodulesOfHead(): array
    {
        $declared = $this->declaredSubmodules('HEAD', null) ?? [];

        return $this->checkedOutSubmodules($declared) ?? $declared;
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
