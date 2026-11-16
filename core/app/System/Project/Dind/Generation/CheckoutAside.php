<?php

namespace App\System\Project\Dind\Generation;

use App\System;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ProjectBindMounts;
use App\System\Project\Git\Path as GitPath;
use Illuminate\Support\Facades\Log;

/**
 * A running app's checkout, set aside while a redeploy prepares the new one: a container bind-mounting ~/project
 * keeps it and serves on. Then removed, or put back with its branch records if the deploy failed and its app runs.
 */
final class CheckoutAside
{
    public const DIR = '.project-prev';

    private const FAILED_DIR = '.project-failed';

    private const COPY_TIMEOUT_SECONDS = 1800;

    private const INSPECT_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly DindProject $project) {}

    public function asidePath(): string
    {
        return rtrim($this->project->homeDirPath(), '/').'/'.self::DIR;
    }

    /**
     * $copyBack when the deploy builds on the files already there; $binds when a container reads the checkout
     * through a bind mount. False when it could not, and the caller goes on as before.
     *
     * @param  list<string>  $containers  the project's running containers
     */
    public function moveAside(array $containers, bool $copyBack, bool $binds = true): bool
    {
        $this->settle();
        $state = new GenerationState($this->project->username());
        if ($state->get(GenerationState::CHECKOUT) !== null || $this->strandedCheckoutInTheWay()) {
            return false;
        }

        $aside = $this->asidePath();
        $records = $this->branchRecords();
        if (! $this->recordBeforeMove($state, $aside, $containers, $binds, $copyBack, $records) || ! $this->moveTree($state, $aside)) {
            return false;
        }

        return ! $copyBack || $this->copyBack($state, $aside);
    }

    /**
     * An unrecorded aside directory is a stranded checkout: put back before the `rm -rf` of a move
     * would delete it, or the move is refused.
     */
    private function strandedCheckoutInTheWay(): bool
    {
        if (! $this->asideExists() || $this->reconcileStranded() === 'restored') {
            return false;
        }
        Log::warning("Leaving a checkout of {$this->project->username()} aside: ~/".self::DIR
            .' exists with no record naming it, and ~/project already does');

        return true;
    }

    /**
     * Recorded before the move: a process killed between the two must not leave a tree aside that nothing names.
     *
     * @param  list<string>  $containers
     * @param  array{git_branch: mixed, branch: mixed}  $records
     */
    private function recordBeforeMove(GenerationState $state, string $aside, array $containers, bool $binds, bool $copyBack, array $records): bool
    {
        try {
            $state->put(GenerationState::CHECKOUT, [
                'path' => $aside,
                'containers' => array_values($containers),
                'binds' => $binds,
                'copy_back_pending' => $copyBack,
                'records' => $records,
                'owner' => GenerationState::owner(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Could not record the checkout of {$this->project->username()} before moving it aside: ".$e->getMessage());

            return false;
        }

        return true;
    }

    /** When nothing moved, the provisional record goes too, so the sweep does not settle it. */
    private function moveTree(GenerationState $state, string $aside): bool
    {
        $system = $this->project->system();
        try {
            $system->exec(Privileged::files()->removeTree($aside), [], 300);
            $system->exec(Privileged::files()->rename($this->project->userAppDirPath(), $aside), [], 120);
        } catch (\Throwable $e) {
            Log::warning("Could not move the checkout of {$this->project->username()} aside: ".$e->getMessage());
            try {
                $state->forget(GenerationState::CHECKOUT);
            } catch (\Throwable) {
            }

            return false;
        }

        return true;
    }

    private function copyBack(GenerationState $state, string $aside): bool
    {
        try {
            $this->project->system()->exec(Privileged::files()->copyTree($aside, $this->project->userAppDirPath()), [], self::COPY_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning("Could not copy the checkout of {$this->project->username()} back: ".$e->getMessage());
            $this->putBack();
            $state->forget(GenerationState::CHECKOUT);

            return false;
        }

        $this->markCopyBackFinished($state);

        return true;
    }

    /** ~/project holds the whole checkout again, so the tree aside is no longer the only copy. */
    private function markCopyBackFinished(GenerationState $state): void
    {
        try {
            $entry = $state->get(GenerationState::CHECKOUT) ?? [];
            $state->put(GenerationState::CHECKOUT, ['copy_back_pending' => false] + $entry);
        } catch (\Throwable $e) {
            Log::warning("Could not record the copy back of {$this->project->username()}: ".$e->getMessage());
        }
    }

    /** Moved aside earlier in this same process: before a pull it then redeploys. */
    public function keptByThisProcess(): bool
    {
        $entry = (new GenerationState($this->project->username()))->get(GenerationState::CHECKOUT);

        return ($entry['owner']['pid'] ?? null) === (int) getmypid();
    }

    /**
     * Before the checkout changes under a running app (a pull, a revert, a push): its tree stays aside, the change
     * lands in a copy. What the app mounts (maybe nothing), or null when nothing was moved.
     *
     * @return ?list<string>
     */
    public function keepForRunningApp(): ?array
    {
        if ($this->keptByThisProcess() || $this->project->app() === null) {
            return null;
        }
        $read = (new ProjectBindMounts($this->project))->read();
        if ($read === null || $read['running'] === []) {
            return null;
        }

        return $this->moveAside($read['running'], copyBack: true, binds: $read['mounts'] !== []) ? $read['mounts'] : null;
    }

    /** A checkout moved aside that running containers bind-mount: they are recreated onto the new one. */
    public static function bindsMovedAside(string $username): bool
    {
        $entry = (new GenerationState($username))->get(GenerationState::CHECKOUT);

        return $entry !== null && ($entry['binds'] ?? true) === true;
    }

    /**
     * Removed after a deploy that succeeded; after one that failed or died,
     * put back while every container it was deployed for still runs.
     */
    public static function outcome(?bool $succeeded, bool $allStillRunning): string
    {
        return $succeeded !== true && $allStillRunning ? 'restored' : 'removed';
    }

    /**
     * 'restored' or 'removed'; null when nothing was aside or it is left for the sweep. $succeeded is the
     * deploy's verdict, null when it is not known. A record with nothing aside is a move that never happened.
     */
    public function settle(?bool $succeeded = null): ?string
    {
        $state = new GenerationState($this->project->username());
        $aside = $state->get(GenerationState::CHECKOUT);
        if ($aside === null) {
            return null;
        }
        if (! $this->asideExists()) {
            $state->forget(GenerationState::CHECKOUT);

            return null;
        }

        $running = $succeeded === true ? false : self::allRunning($this->project, self::recordedContainers($aside));
        if ($running === null) {
            return null;
        }
        $outcome = $this->outcomeKeepingOneCopy($succeeded, $running, $aside);
        if (! $this->apply($outcome)) {
            return null;
        }
        $state->forget(GenerationState::CHECKOUT);
        if ($outcome === 'restored') {
            $this->recordsBack($aside);
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $aside  the CHECKOUT entry
     * @return list<string>
     */
    private static function recordedContainers(array $aside): array
    {
        return array_values(array_filter((array) ($aside['containers'] ?? []), 'is_string'));
    }

    /**
     * {@see outcome()}, but never 'removed' while the tree aside is the only
     * whole copy: ~/project is gone, or a copy back into it never finished.
     *
     * @param  array<string, mixed>  $aside  the CHECKOUT entry
     */
    private function outcomeKeepingOneCopy(?bool $succeeded, bool $running, array $aside): string
    {
        $outcome = self::outcome($succeeded, $running);
        if ($outcome !== 'removed') {
            return $outcome;
        }

        $partialCopy = ($aside['copy_back_pending'] ?? false) === true;
        if ($partialCopy || ! $this->project->system()->filesystem()->directoryExists($this->project->userAppDirPath())) {
            return 'restored';
        }

        return $outcome;
    }

    private function apply(string $outcome): bool
    {
        try {
            if ($outcome === 'restored') {
                $this->putBack();
            } else {
                $this->project->system()->exec(Privileged::files()->removeTree($this->asidePath()), [], 600);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not settle the checkout of {$this->project->username()}: ".$e->getMessage());

            return false;
        }

        return true;
    }

    /** The tree a running app was deployed from is still aside, to start the previous version from. */
    public function restorable(): bool
    {
        return (new GenerationState($this->project->username()))->get(GenerationState::CHECKOUT) !== null
            && $this->project->system()->filesystem()->directoryExists($this->asidePath());
    }

    /**
     * An aside directory no record names (moved by hand, or by a process that recorded nothing):
     * put back while ~/project is gone or empty, never deleted.
     *
     * @return ?string 'restored', 'not-restorable' when ~/project holds files, or
     *                 null when nothing is stranded or the move failed
     */
    public function reconcileStranded(): ?string
    {
        $state = new GenerationState($this->project->username());
        if ($state->get(GenerationState::CHECKOUT) !== null || ! $this->asideExists()) {
            return null;
        }
        if (! $this->projectDirMissingOrEmpty()) {
            return 'not-restorable';
        }

        try {
            $this->project->system()->exec(
                Privileged::files()->rename($this->asidePath(), $this->project->userAppDirPath()),
                [],
                120
            );
        } catch (\Throwable $e) {
            Log::warning("Could not put a stranded checkout of {$this->project->username()} back: ".$e->getMessage());

            return null;
        }

        return 'restored';
    }

    /** A directory that cannot be listed is read as holding files: nothing is moved onto what cannot be seen. */
    private function projectDirMissingOrEmpty(): bool
    {
        $filesystem = $this->project->system()->filesystem();
        $projectDir = $this->project->userAppDirPath();
        if (! $filesystem->directoryExists($projectDir)) {
            return true;
        }
        try {
            return array_filter($filesystem->ls($projectDir), static fn (string $entry): bool => $entry !== '') === [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Accounts whose home holds an aside directory, recorded or not: one host command for every home.
     *
     * @return list<string>
     */
    public static function accountsWithAside(System $system): array
    {
        try {
            $homes = $system->exec(
                Privileged::files()->parentsOfDirectoriesTwoLevelsDownNamed($system->homesDirPath(), self::DIR),
                [],
                self::INSPECT_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_map('basename', array_filter(array_map('trim', explode("\n", $homes))))));
    }

    private function asideExists(): bool
    {
        return $this->project->system()->filesystem()->directoryExists($this->asidePath());
    }

    /** Back in place whatever runs now: the previous version starts from it again. */
    public function bringBack(): bool
    {
        $state = new GenerationState($this->project->username());
        $aside = $state->get(GenerationState::CHECKOUT) ?? [];
        try {
            $this->putBack();
        } catch (\Throwable $e) {
            Log::warning("Could not put the previous checkout of {$this->project->username()} back: ".$e->getMessage());

            return false;
        }
        $state->forget(GenerationState::CHECKOUT);
        $this->recordsBack($aside);

        return true;
    }

    /**
     * The branch the project's records name for this checkout: a branch change rewrites them before its
     * rebuild, while the tree aside is still on this one. `branch` is null when it is read from git_branch.
     *
     * @return array{git_branch: mixed, branch: mixed}
     */
    private function branchRecords(): array
    {
        $details = $this->project->userModel()->getDetails();
        $entry = $details['site_git'][$this->pathKey()] ?? null;

        return [
            'git_branch' => $details['git_branch'] ?? null,
            'branch' => is_array($entry) ? ($entry['branch'] ?? '') : null,
        ];
    }

    /**
     * The tree aside is back: so is the branch the records named for it, or
     * the next pull would fetch the other branch into it.
     *
     * @param  array<string, mixed>  $aside  the CHECKOUT entry
     */
    private function recordsBack(array $aside): void
    {
        $records = $aside['records'] ?? null;
        try {
            if (! is_array($records) || $records === $this->branchRecords()) {
                return;
            }
            $this->writeBranchRecords($records);
        } catch (\Throwable $e) {
            Log::warning("Could not put the branch records of {$this->project->username()} back: ".$e->getMessage());
        }
    }

    /** @param array<string, mixed> $records {@see branchRecords()} */
    private function writeBranchRecords(array $records): void
    {
        $user = $this->project->userModel();
        $site = $user->getDetails()['site_git'] ?? [];
        $site = is_array($site) ? $site : [];
        $key = $this->pathKey();
        if (($records['branch'] ?? null) === null) {
            unset($site[$key]);
        } elseif (is_array($site[$key] ?? null)) {
            $site[$key]['branch'] = $records['branch'];
        }
        $user->setDetails(['site_git' => $site, 'git_branch' => $records['git_branch'] ?? null]);
        if ($user->exists) {
            $user->save();
        }
    }

    private function pathKey(): string
    {
        return GitPath::key($this->project->userAppDirPath(), $this->project->homeDirPath());
    }

    /** The old tree back where its containers' configuration says it is. */
    private function putBack(): void
    {
        $system = $this->project->system();
        $projectDir = $this->project->userAppDirPath();
        $failed = rtrim($this->project->homeDirPath(), '/').'/'.self::FAILED_DIR;
        if (! $this->asideExists()) {
            throw new \RuntimeException('nothing is aside to put back');
        }
        $system->exec(Privileged::files()->removeTree($failed), [], 600);
        if ($system->filesystem()->directoryExists($projectDir)) {
            $system->exec(Privileged::files()->rename($projectDir, $failed), [], 120);
        }
        $system->exec(Privileged::files()->rename($this->asidePath(), $projectDir), [], 120);
        $system->exec(Privileged::files()->removeTree($failed), [], 600);
    }

    /**
     * Null when the daemon could not be asked, so a daemon not up yet is not read as every container gone.
     *
     * @param  list<string>  $ids
     */
    public static function allRunning(DindProject $project, array $ids): ?bool
    {
        if ($ids === []) {
            return false;
        }
        try {
            $inspected = $project->accountDaemon()->containers($ids, self::INSPECT_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return self::allTrue($inspected, count($ids));
    }

    /** @param  list<array<string, mixed>|null>  $inspected */
    public static function allTrue(array $inspected, int $expected): bool
    {
        $running = array_filter($inspected, static fn (?array $container): bool => ($container['State']['Running'] ?? null) === true);

        return count($inspected) === $expected && count($running) === $expected;
    }
}
