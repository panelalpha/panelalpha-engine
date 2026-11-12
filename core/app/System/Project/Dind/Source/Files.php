<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use App\Lib\Deploy\Source\ProjectArchive;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Models\User;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use Symfony\Component\Process\Process;

/**
 * Archive ingest into ~/project (no deploy preparation).
 */
final class Files
{
    private const ARCHIVE_STAGE_DIR = '/var/lib/panelalpha/archive-stage';

    private const EXTRACT_PREFIX = 'archive-extract-';

    /** Run by the engine, so the account never writes the staged copy `$0`. */
    private const WRITE_OUTPUT_TO = 'exec "$@" > "$0"';

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
        [$uid, $gid] = $this->accountIds($user);

        $stageDir = $this->stageRoot.'/'.bin2hex(random_bytes(8));
        $staged = $stageDir.'/'.$archive->stagedName();
        $tmp = $home.'/.panelalpha/'.self::EXTRACT_PREFIX.bin2hex(random_bytes(8));

        try {
            $this->logger()?->info('Copying the project archive '.basename($archive->path).' into the stage directory');
            $this->stage($archive, $stageDir, $staged, $uid, $gid);
            $this->logger()?->info('Unpacking the project archive');
            $this->extractAsAccount($archive, $staged, $tmp, $uid, $gid);
            $this->logger()?->info('Writing the project archive into ~/project');
            $this->syncIntoProject($this->projectRootIn($tmp, $uid, $gid), $projectDir, $uid, $gid, $chown);
        } finally {
            $system->exec(Privileged::files()->removeTree($tmp, $stageDir));
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function accountIds(User $user): array
    {
        $uid = $user->getUid();
        $gid = $user->getGid();
        if ($uid === null || $gid === null) {
            throw new \RuntimeException(
                "Account {$user->username} has no uid/gid recorded; cannot extract as the account."
            );
        }

        return [$uid, $gid];
    }

    /**
     * Copy the archive 0444 into a directory of the engine's, then check its contents and unpacked size against
     * that copy, which cannot change underneath the checks.
     */
    private function stage(UploadedArchive $archive, string $stageDir, string $staged, int $uid, int $gid): void
    {
        $system = $this->project->system();
        $this->makeStageDirectory($stageDir);
        $system->exec(['sh', '-c', self::WRITE_OUTPUT_TO, $staged, ...Privileged::account()->readRegularFile($uid, $gid, $archive->path)]);
        $this->changeMode($staged, 0444);

        $archive->assertSafeContents($staged, fn (array $argv): string => $system->exec($argv, [], 120));
        ArchiveUnpackedSize::assertWithin($staged, $archive->isZip);
    }

    private function makeStageDirectory(string $stageDir): void
    {
        if (! @mkdir($stageDir, 0755, true) && ! is_dir($stageDir)) {
            throw new \RuntimeException("Could not create the archive stage directory {$stageDir}");
        }
        $this->changeMode($stageDir, 0755);
    }

    private function changeMode(string $path, int $mode): void
    {
        if (! @chmod($path, $mode)) {
            throw new \RuntimeException(sprintf('Could not set mode %o on %s', $mode, $path));
        }
    }

    /** Root makes only ~/.panelalpha, the account the extract directory inside it. */
    private function extractAsAccount(UploadedArchive $archive, string $staged, string $tmp, int $uid, int $gid): void
    {
        $system = $this->project->system();
        $system->exec(Privileged::scripts()->makeHomeEntry(dirname($tmp), '0700', $uid.':'.$gid));
        $system->exec(Privileged::accountFiles($uid, $gid)->makeDirectories($tmp));
        $this->reportExtraction($archive, $staged, $system->runProcess($archive->extractArgv($uid, $gid, $staged, $tmp), [], 600));
        $system->exec($archive->normaliseModesArgv($uid, $gid, $tmp), [], 120);
    }

    private function reportExtraction(UploadedArchive $archive, string $staged, Process $extraction): void
    {
        $archive->assertExtracted($extraction, $staged);
        foreach ($archive->extractionWarnings($extraction, $staged) as $warning) {
            $this->logger()?->warn($warning);
        }
    }

    /**
     * Listed as the account, since core's PHP sees ~/.panelalpha (0700) as empty.
     */
    private function projectRootIn(string $tmp, int $uid, int $gid): string
    {
        $listing = $this->project->system()->exec(ProjectArchive::topLevelArgv(Privileged::accountFiles($uid, $gid), $tmp), [], 60);

        return ProjectArchive::resolveProjectRoot($tmp, $listing);
    }

    /**
     * Compared by content, since archives often carry normalised mtimes: a changed file gets a
     * new mtime, or BuildKit's stat-based context sync keeps sending the old one into the image.
     */
    private function syncIntoProject(string $source, string $projectDir, int $uid, int $gid, ?string $chown): void
    {
        $system = $this->project->system();
        $system->exec(Privileged::scripts()->makeHomeEntry($projectDir, '0755', $uid.':'.$gid));
        $system->exec(Privileged::files()->mirrorByContent($source, $projectDir));
        if ($chown) {
            $system->exec(Privileged::files()->changeOwnerRecursively($chown, $projectDir));
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
                $system->exec(Privileged::files()->removeTree($path), [], 600);
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
            $listing = $this->project->system()->exec(Privileged::files()->staleDirectoriesStartingWith($root, $prefix, self::STALE_MINUTES), [], 60);
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
