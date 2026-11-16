<?php

namespace App\System\Project\Backup;

use App\Support\JobBudget;
use App\System\Privileged;
use App\System\Project\HomeMerge;
use App\System\Project\HomeSwap;
use Throwable;

/** A classic account's whole home: archived, and restored by a swap or a merge. */
class HomeFiles extends AccountHome
{
    private ?HomeSwap $lastSwap = null;

    /** The whole home, preserving ownership, with the push and restore scratch directories left out. */
    public function archive(string $dest): void
    {
        $this->project->system()->exec(
            Privileged::hostFiles()->createArchive($this->home(), $dest, '.', ['./.incoming', './.pre-push']),
            [],
            $this->deadline->stepSeconds(),
        );
    }

    /**
     * Root creates the scratch directory, as the home root is root-owned (Project::createHomeDirectory chowns it), and
     * keeps it 700 while tar runs, so no directory tar already handed over is swapped for a link under a later member.
     */
    public function extract(string $archivePath, string $targetDir): void
    {
        $this->project->system()->exec(Privileged::hostFiles()->makeDirectoriesWithMode('700', $targetDir));
        $this->project->system()->exec(
            Privileged::hostFiles()->extractArchive($archivePath, $targetDir),
            [],
            $this->deadline->stepSeconds(),
        );
    }

    /**
     * delete_existing_files=false: the live home stays writable by the account's PHP and cron, so
     * root never extracts into it. The account unpacks and merges the archive. {@see HomeMerge}
     */
    public function merge(string $archivePath, string $incomingDir): void
    {
        $this->homeMerge()->merge($archivePath, $this->path($incomingDir));
    }

    /**
     * Remove a pre-existing scratch entry, link or not, without following a symlink at it: a leftover from an earlier
     * failed run must not let a stale symlink become the extraction target.
     */
    public function removeEntry(string $entry): void
    {
        $this->project->system()->exec(
            Privileged::hostFiles()->removeTree($this->path($entry)),
            [],
            JobBudget::CLEANUP_SECONDS,
        );
    }

    /**
     * An aside a failed rollback left holds the home's own files: refused, never deleted.
     *
     * @throws \RuntimeException
     */
    public function removeEmptyAside(string $asideDir): void
    {
        $aside = $this->path($asideDir);
        if (! $this->existsOnHost($aside)) {
            return;
        }
        try {
            $this->project->system()->exec(Privileged::hostFiles()->removeEmptyDirectory($aside), [], JobBudget::CLEANUP_SECONDS);
        } catch (Throwable) {
            throw new \RuntimeException("{$aside} still holds the files an earlier restore moved aside; move them back into the home before restoring again.");
        }
    }

    /**
     * @param  list<string>  $skipEntries
     */
    public function swap(string $incomingDir, string $asideDir, array $skipEntries): void
    {
        $this->lastSwap = $this->homeSwap($this->deadline->stepSeconds());
        $this->lastSwap->swap(
            $this->path($incomingDir),
            $this->path($asideDir),
            $skipEntries,
            'restore',
        );
    }

    /** Undoes what {@see swap()} moved, however far it got: nothing it did not move is touched. */
    public function rollback(string $incomingDir, string $asideDir): void
    {
        $this->lastSwap?->withTimeout(JobBudget::CLEANUP_SECONDS)->undo(
            $this->path($incomingDir),
            $this->path($asideDir),
            'restore',
        );
    }

    public function discardAside(string $asideDir): void
    {
        $this->project->system()->exec(
            Privileged::hostFiles()->removeTree($this->path($asideDir)),
            [],
            JobBudget::CLEANUP_SECONDS,
        );
    }

    private function homeSwap(int $timeout): HomeSwap
    {
        return new HomeSwap(
            $this->project->system(),
            $this->home(),
            $timeout,
        );
    }

    private function homeMerge(): HomeMerge
    {
        [$uid, $gid] = $this->project->model()->linuxIds();

        return new HomeMerge(
            $this->project->system(),
            $this->home(),
            (string) $uid,
            (string) $gid,
            $this->deadline->stepSeconds(),
        );
    }
}
