<?php

namespace App\System\Project\Backup;

use App\Lib\Backup\ArchivePriority;
use App\Support\JobBudget;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A DinD account's ~/project: archived, and restored by swapping a whole tree in. */
class ProjectFiles extends AccountHome
{
    private const string FAILED_RESTORE = 'project.failed-restore';

    /** Root's, 700, directly under the homes and so on their filesystem: no account container mounts it. */
    private const string STAGE = '.panelalpha-restore-stage';

    /** Runs on the job budget: a large ~/project outlasts exec()'s 600 s default. */
    public function archive(string $dest): void
    {
        $this->project->system()->exec(ArchivePriority::configured()->createArchive($this->home(), $dest, 'project'), [], $this->deadline->stepSeconds());
    }

    /**
     * Root extracts outside every home, where the account's root container could swap a directory for a link mid-tar.
     * The stage sits beside the homes, so the finished tree goes in with one rename.
     */
    public function extract(string $archivePath, string $incomingDir): void
    {
        $incoming = $this->path($incomingDir);
        $stage = $this->stage();
        $files = Privileged::hostFiles();
        $system = $this->project->system();
        $system->exec($files->removeTree($incoming, $stage), [], $this->deadline->stepSeconds());
        try {
            $system->exec($files->makeDirectoriesWithMode('700', dirname($stage)));
            $system->exec($files->makeDirectoryWithMode('700', $stage));
            $system->exec($files->extractArchive($archivePath, $stage, 1), [], $this->deadline->stepSeconds());
            $system->exec($files->changeMode('755', $stage));
            $owner = $this->project->model()->getChownString();
            if ($owner !== null) {
                $system->exec($files->changeOwner($owner, $stage));
            }
            $system->exec($files->rename($stage, $incoming));
        } catch (Throwable $e) {
            $this->discardStage($stage);

            throw $e;
        }
    }

    public function swap(string $incomingDir, string $asideDir): void
    {
        $live = $this->home().'/project';
        $incoming = $this->path($incomingDir);
        $aside = $this->path($asideDir);

        if ($this->existsOnHost($aside)) {
            throw new \RuntimeException(
                'Pre-restore aside already exists at '.$aside.'; refusing to overwrite',
            );
        }

        $this->project->system()->exec(Privileged::hostFiles()->move($live, $aside));
        $this->project->system()->exec(Privileged::hostFiles()->move($incoming, $live));
    }

    public function rollback(string $asideDir): void
    {
        $live = $this->home().'/project';
        $failed = $this->path(self::FAILED_RESTORE);

        $this->moveFailedTreeAside($live, $failed);
        $this->project->system()->exec(Privileged::hostFiles()->move($this->path($asideDir), $live), [], JobBudget::CLEANUP_SECONDS);
        $this->discardFailedTree($failed);
    }

    public function discardAside(string $asideDir): void
    {
        $this->project->system()->exec(Privileged::hostFiles()->removeTree($this->path($asideDir)), [], JobBudget::CLEANUP_SECONDS);
    }

    /** Renamed rather than deleted, so `~/project` is only missing between two renames. */
    private function moveFailedTreeAside(string $live, string $failed): void
    {
        if (! $this->existsOnHost($live)) {
            return;
        }
        if ($this->existsOnHost($failed)) {
            $this->project->system()->exec(Privileged::hostFiles()->removeTree($failed), [], JobBudget::CLEANUP_SECONDS);
        }
        $this->project->system()->exec(Privileged::hostFiles()->move($live, $failed), [], JobBudget::CLEANUP_SECONDS);
    }

    private function stage(): string
    {
        return rtrim($this->project->system()->homesDirPath(), '/').'/'.self::STAGE.'/'.$this->project->model()->username;
    }

    private function discardStage(string $stage): void
    {
        try {
            $this->project->system()->exec(Privileged::hostFiles()->removeTree($stage), [], JobBudget::CLEANUP_SECONDS);
        } catch (Throwable $e) {
            Log::warning('Backup could not remove the restore stage', ['path' => $stage, 'error' => $e->getMessage()]);
        }
    }

    /** The pre-restore tree is already back, so a failed tree that will not go is only logged. */
    private function discardFailedTree(string $failed): void
    {
        try {
            $this->project->system()->exec(Privileged::hostFiles()->removeTree($failed), [], JobBudget::CLEANUP_SECONDS);
        } catch (Throwable $e) {
            Log::warning('Backup could not remove the failed restore tree', [
                'path' => $failed,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
