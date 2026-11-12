<?php

namespace App\System\Project\Dind\Source;

use App\System\Privileged;
use App\System\Project\Dind as DindProject;

/**
 * ~/project tree helpers shared by git and archive ingest.
 */
final class ProjectTree
{
    public function __construct(
        private DindProject $project,
    ) {}

    public function appDirPath(): string
    {
        return $this->project->homeDirPath().'/project';
    }

    /**
     * Where a rebuild clones before it replaces ~/project; same filesystem, so the move is a rename.
     * Each run gets its own, so an abandoned clone of an earlier run cannot collide with this one.
     */
    public function stagingDirPath(): string
    {
        return $this->project->homeDirPath().'/.project-next-'.bin2hex(random_bytes(6));
    }

    /**
     * Every staging directory an earlier rebuild left behind, the old fixed name included.
     * The deploy lock keeps a second rebuild of the account from running meanwhile.
     */
    public function removeAbandonedStaging(): void
    {
        $home = $this->project->homeDirPath();
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+$#', $home) !== 1) {
            throw new \InvalidArgumentException('Refusing to clean staging outside a home directory.');
        }

        $this->project->system()->exec(Privileged::files()->removeEntriesNamed($home, '.project-next*'), [], 120);
    }

    public function removeStaging(string $stagingDir): void
    {
        self::assertStagingDir($stagingDir);

        $this->project->system()->exec(Privileged::files()->removeTree($stagingDir), [], 120);
    }

    public function moveStagingInto(string $stagingDir, string $projectDir): void
    {
        self::assertStagingDir($stagingDir);
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+/project$#', $projectDir) !== 1) {
            throw new \InvalidArgumentException('Refusing to move into a path outside ~/project.');
        }

        $this->project->system()->exec(Privileged::files()->moveEntriesInto($stagingDir, $projectDir), [], 120);
        $this->project->system()->exec(Privileged::files()->removeEmptyDirectory($stagingDir), [], 120);
    }

    private static function assertStagingDir(string $stagingDir): void
    {
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+/\.project-next-[0-9a-f]+$#', $stagingDir) !== 1) {
            throw new \InvalidArgumentException('Refusing to touch a path outside a ~/.project-next staging directory.');
        }
    }

    /**
     * Clear ~/project without removing the directory itself.
     */
    public function clearContents(string $projectDir): void
    {
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+/project$#', $projectDir) !== 1) {
            throw new \InvalidArgumentException('Refusing to clear a path outside ~/project.');
        }

        $this->project->system()->exec(Privileged::files()->emptyDirectory($projectDir), [], 120);
    }
}
