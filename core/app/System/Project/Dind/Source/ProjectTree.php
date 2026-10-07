<?php

namespace App\System\Project\Dind\Source;

use App\System\Project\Dind as DindProject;

/**
 * ~/project tree helpers shared by git and archive ingest.
 */
final class ProjectTree
{
    public function __construct(
        private DindProject $project,
    ) {
    }

    public function appDirPath(): string
    {
        return $this->project->homeDirPath() . '/project';
    }

    /** Where a rebuild clones before it replaces ~/project; same filesystem, so the move is a rename. */
    public function stagingDirPath(): string
    {
        return $this->project->homeDirPath() . '/.project-next';
    }

    public function removeStaging(string $stagingDir): void
    {
        self::assertStagingDir($stagingDir);

        $this->project->system()->exec(['sudo', 'rm', '-rf', $stagingDir], [], 120);
    }

    public function moveStagingInto(string $stagingDir, string $projectDir): void
    {
        self::assertStagingDir($stagingDir);
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+/project$#', $projectDir) !== 1) {
            throw new \InvalidArgumentException('Refusing to move into a path outside ~/project.');
        }

        $this->project->system()->exec([
            'sudo', 'find', $stagingDir,
            '-mindepth', '1', '-maxdepth', '1',
            '-exec', 'mv', '-t', $projectDir, '{}', '+',
        ], [], 120);
        $this->project->system()->exec(['sudo', 'rmdir', $stagingDir], [], 120);
    }

    private static function assertStagingDir(string $stagingDir): void
    {
        if (preg_match('#^/home/[a-zA-Z0-9_.-]+/\.project-next$#', $stagingDir) !== 1) {
            throw new \InvalidArgumentException('Refusing to touch a path outside ~/.project-next.');
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

        $this->project->system()->exec([
            'sudo', 'find', $projectDir,
            '-mindepth', '1', '-maxdepth', '1',
            '-exec', 'rm', '-rf', '{}', '+',
        ], [], 120);
    }
}
