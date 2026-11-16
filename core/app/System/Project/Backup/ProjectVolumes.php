<?php

namespace App\System\Project\Backup;

use App\Support\JobBudget;
use App\Support\JobDeadline;
use App\System\Project as UserProject;
use App\System\Project\Dind\VolumeArchive;

/** The named volumes of a DinD account's inner stack. */
class ProjectVolumes
{
    public function __construct(
        private UserProject $project,
        private JobDeadline $deadline,
    ) {}

    /**
     * @return list<array{name: string, docker_name: string}>
     */
    public function names(): array
    {
        return $this->archiver($this->deadline->stepSeconds())->namedVolumes();
    }

    public function archive(string $dockerName, string $dest): void
    {
        $this->archiver($this->deadline->stepSeconds())->tarVolume($dockerName, $dest);
    }

    public function ensure(string $dockerName): void
    {
        $this->archiver($this->deadline->stepSeconds())->ensureVolume($dockerName);
    }

    public function restore(string $dockerName, string $archivePath): void
    {
        $this->archiver($this->deadline->stepSeconds())->restoreVolume($dockerName, $archivePath);
    }

    public function rollback(string $dockerName): void
    {
        $this->archiver(JobBudget::CLEANUP_SECONDS)->rollbackVolume($dockerName);
    }

    private function archiver(int $timeout): VolumeArchive
    {
        return new VolumeArchive(DindRuntime::of($this->project), $timeout);
    }
}
