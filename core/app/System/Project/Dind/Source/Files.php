<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use App\Lib\Deploy\Source\ProjectArchive;
use App\Lib\Deploy\Source\UploadedArchive;
use App\System\Project\Dind as DindProject;

/**
 * Archive ingest into ~/project (no deploy preparation).
 */
final class Files
{
    private const ARCHIVE_STAGE_DIR = '/var/lib/panelalpha/archive-stage';

    private const EXTRACT_PREFIX = 'archive-extract-';

    /** Past this age an import's leftovers are a killed run's, not a live one's. */
    private const STALE_MINUTES = 1440;

    private ?ProjectTree $tree = null;

    public function __construct(
        private DindProject $project,
        private string $stageRoot = self::ARCHIVE_STAGE_DIR,
    ) {}

    /**
     * Import an uploaded archive into ~/project. Leftovers of killed imports are swept first:
     * a killed import skips its `finally`, and nothing else removes what it left.
     */
    public function importProjectArchive(string $zipPath): void
    {
        $this->sweepStale();

        $user = $this->project->userModel();
        $system = $this->project->system();
        $home = rtrim($this->project->homeDirPath(), '/');
        $projectDir = $this->tree()->appDirPath();
        $chown = $user->getChownString();

        $archive = UploadedArchive::inHome($zipPath, $home, $this->project->project()->fileManager()->resolvedFile(...));
        $uid = $user->getUid();
        $gid = $user->getGid();
        if ($uid === null || $gid === null) {
            throw new \RuntimeException(
                "Account {$user->username} has no uid/gid recorded; cannot extract as the account."
            );
        }

        $stageDir = $this->stageRoot.'/'.bin2hex(random_bytes(8));
        $staged = $stageDir.'/'.$archive->stagedName();
        $tmp = $home.'/.panelalpha/'.self::EXTRACT_PREFIX.bin2hex(random_bytes(8));

        try {
            $this->logger()?->info('Copying the project archive into the stage directory');
            $this->stage($archive, $stageDir, $staged);
            $this->logger()?->info('Unpacking the project archive');
            $this->extractAsAccount($archive, $staged, $tmp, $uid, $gid, $chown);
            $this->logger()?->info('Writing the project archive into ~/project');
            $this->syncIntoProject($this->projectRootIn($tmp), $projectDir, $chown);
        } finally {
            $system->exec(['sudo', 'rm', '-rf', $tmp, $stageDir]);
        }
    }

    /**
     * Copy the archive root-owned and 0444, then check its contents and unpacked size against
     * that copy, which cannot change underneath the checks.
     */
    private function stage(UploadedArchive $archive, string $stageDir, string $staged): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', '-p', $stageDir]);
        $system->exec(['sudo', 'chmod', '0755', $stageDir]);
        $system->exec(['sudo', 'cp', '--no-dereference', $archive->path, $staged]);
        $system->exec(['sudo', 'chmod', '0444', $staged]);

        $archive->assertSafeContents($staged, fn (array $argv): string => $system->exec($argv, [], 120));
        ArchiveUnpackedSize::assertWithin($staged, $archive->isZip);
    }

    private function extractAsAccount(UploadedArchive $archive, string $staged, string $tmp, int $uid, int $gid, ?string $chown): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', '-p', $tmp]);
        if ($chown) {
            $system->exec(['sudo', 'chown', $chown, $tmp]);
        }
        $system->exec($archive->extractArgv($uid, $gid, $staged, $tmp), [], 600);
        $system->exec($archive->normaliseModesArgv($uid, $gid, $tmp), [], 120);
    }

    /**
     * Listed as root: ~/.panelalpha is the account's alone (0700), so core's PHP would see an
     * empty directory and never unwrap it.
     */
    private function projectRootIn(string $tmp): string
    {
        $system = $this->project->system();

        return ProjectArchive::resolveProjectRoot($tmp, $system->exec(ProjectArchive::topLevelArgv($tmp), [], 60));
    }

    /**
     * Compared by content, since archives often carry normalised mtimes: a changed file gets a
     * new mtime, or BuildKit's stat-based context sync keeps sending the old one into the image.
     */
    private function syncIntoProject(string $source, string $projectDir, ?string $chown): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', '-p', $projectDir]);
        $system->exec([
            'sudo',
            'rsync',
            '-a',
            '--delete',
            '--checksum',
            '--no-times',
            rtrim($source, '/').'/',
            rtrim($projectDir, '/').'/',
        ]);
        if ($chown) {
            $system->exec(['sudo', 'chown', '-R', $chown, $projectDir]);
        }
    }

    /**
     * Leftovers older than a day from killed imports, in the stage root and ~/.panelalpha.
     */
    private function sweepStale(): void
    {
        $this->sweepDirectories($this->stageRoot);
        $this->sweepDirectories(rtrim($this->project->homeDirPath(), '/').'/.panelalpha', self::EXTRACT_PREFIX);
    }

    /**
     * Best effort, as root (the stage root is root-owned and ~/.panelalpha is the account's 0700):
     * a missing root has nothing to sweep, and a leak left behind must not fail a new import.
     */
    private function sweepDirectories(string $root, string $prefix = ''): void
    {
        $system = $this->project->system();
        foreach ($this->staleDirectories($root, $prefix) as $path) {
            try {
                $system->exec(['sudo', 'rm', '-rf', $path], [], 600);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function staleDirectories(string $root, string $prefix): array
    {
        try {
            $listing = $this->project->system()->exec([
                'sudo', 'find', $root, '-mindepth', '1', '-maxdepth', '1',
                '-type', 'd', '-name', $prefix.'*', '-mmin', '+'.self::STALE_MINUTES,
                '-print',
            ], [], 60);
        } catch (\Throwable) {
            return [];
        }

        return array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: []));
    }

    private function tree(): ProjectTree
    {
        return $this->tree ??= new ProjectTree($this->project);
    }

    private function logger(): ?DeployLogger
    {
        return $this->project->shell()->logger();
    }
}
