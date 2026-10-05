<?php

namespace App\System\Project\Dind\Source;

use App\Lib\Deploy\Source\ArchiveSafety;
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

    private ?ProjectTree $tree = null;

    public function __construct(
        private DindProject $project,
        private string $stageRoot = self::ARCHIVE_STAGE_DIR,
    ) {
    }

    public function importProjectArchive(string $zipPath): void
    {
        $user = $this->project->userModel();
        $system = $this->project->system();
        $home = rtrim($this->project->homeDirPath(), '/');
        $projectDir = $this->tree()->appDirPath();
        $chown = $user->getChownString();

        $archive = UploadedArchive::inHome($zipPath, $home);
        $uid = $user->getUid();
        $gid = $user->getGid();
        if ($uid === null || $gid === null) {
            throw new \RuntimeException(
                "Account {$user->username} has no uid/gid recorded; cannot extract as the account."
            );
        }

        $stageDir = $this->stageRoot . '/' . bin2hex(random_bytes(8));
        $staged = $stageDir . '/' . $archive->stagedName();
        $tmp = $home . '/.panelalpha/archive-extract-' . bin2hex(random_bytes(8));

        try {
            $system->exec(['sudo', 'mkdir', '-p', $stageDir]);
            $system->exec(['sudo', 'chmod', '0755', $stageDir]);
            $system->exec(['sudo', 'cp', '--no-dereference', $archive->path, $staged]);
            $system->exec(['sudo', 'chmod', '0444', $staged]);

            $names = $system->exec($archive->listNamesArgv($staged), [], 120);
            ArchiveSafety::assertSafeListing($names);
            // One listing, three checks: member types, where each symlink
            // points, and the size the archive claims -- which is worth
            // rejecting on when it is already too big.
            $modes = $system->exec($archive->listModesArgv($staged), [], 120);
            ArchiveSafety::assertRegularMembersOnly($modes);
            $members = ArchiveSafety::names($names);
            ArchiveSafety::assertLinksStayInside($this->linkTargets($archive, $staged, $modes, $members), $members);
            ArchiveSafety::assertUncompressedSizeWithin($modes);
            // Then the size it really is. The staged copy is root-owned and
            // 0444, so it can be read here and cannot change underneath us.
            ArchiveUnpackedSize::assertWithin($staged, $archive->isZip);

            $system->exec(['sudo', 'mkdir', '-p', $tmp]);
            if ($chown) {
                $system->exec(['sudo', 'chown', $chown, $tmp]);
            }
            $system->exec($archive->extractArgv($uid, $gid, $staged, $tmp), [], 600);
            $system->exec($archive->normaliseModesArgv($uid, $gid, $tmp), [], 120);

            // Listed as root: ~/.panelalpha is the account's alone (0700), so
            // core's PHP would see an empty directory and never unwrap it.
            $source = ProjectArchive::resolveProjectRoot($tmp, $system->exec(ProjectArchive::topLevelArgv($tmp), [], 60));
            $system->exec(['sudo', 'mkdir', '-p', $projectDir]);
            // Compared by content: archives often carry normalised mtimes. A
            // changed file gets a new mtime, or BuildKit's context sync (stat
            // based) keeps sending the old one into the image.
            $system->exec([
                'sudo',
                'rsync',
                '-a',
                '--delete',
                '--checksum',
                '--no-times',
                rtrim($source, '/') . '/',
                rtrim($projectDir, '/') . '/',
            ]);
            if ($chown) {
                $system->exec(['sudo', 'chown', '-R', $chown, $projectDir]);
            }
        } finally {
            $system->exec(['sudo', 'rm', '-rf', $tmp, $stageDir]);
        }
    }

    /**
     * Each symlink member's target: tar prints it in the listing, zip stores
     * it as the member's contents.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private function linkTargets(UploadedArchive $archive, string $staged, string $modes, array $names): array
    {
        if (!$archive->isZip) {
            return ArchiveSafety::tarLinks($modes, $names);
        }
        $links = [];
        foreach (ArchiveSafety::zipLinkNames($modes, $names) as $name) {
            $links[$name] = $this->project->system()->exec($archive->readMemberArgv($staged, $name), [], 60);
        }

        return $links;
    }

    private function tree(): ProjectTree
    {
        return $this->tree ??= new ProjectTree($this->project);
    }

}
