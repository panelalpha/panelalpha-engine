<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Pause/resume and DinD volume staging for clone/push (not part of the public Project contract).
 */
final class CopyVolumes
{
    public function __construct(
        private DindProject $project,
        private ContainerOperations $containers,
        private Paths $paths,
    ) {}

    public function pauseForCopy(): void
    {
        try {
            $this->containers->projectAction('stop');
        } catch (\Throwable $e) {
            Log::warning('Could not stop inner app before copy', [
                'username' => $this->project->username(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function resumeAfterCopy(): void
    {
        try {
            $this->containers->projectAction('up');
        } catch (\Throwable $e) {
            Log::warning('Could not start inner app after copy', [
                'username' => $this->project->username(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function prepareVolumes(?string $composeDir = null): void
    {
        $dir = $composeDir !== null ? rtrim($composeDir, '/') : $this->paths->appDir();
        $cmd = array_merge($this->paths->composeCommandForDirectory($dir), ['create']);
        try {
            $this->project->shell()->execAsUser($cmd, [], 600);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to prepare volumes: '.$e->getMessage(), 0, $e);
        }
    }

    public function copyVolumeDataFrom(string $sourceHomeDir): int
    {
        $srcVolumes = rtrim($sourceHomeDir, '/').'/docker/volumes';
        $destVolumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';

        if (! $this->pathIsDirectoryAsRoot($srcVolumes) || ! $this->pathIsDirectoryAsRoot($destVolumes)) {
            return 0;
        }

        $copied = 0;
        foreach ($this->listDockerVolumeEntriesAsRoot($srcVolumes) as $entry) {
            $srcData = "{$srcVolumes}/{$entry}/_data";
            $destData = "{$destVolumes}/{$entry}/_data";
            if (! $this->pathIsDirectoryAsRoot($srcData)) {
                continue;
            }
            if (! $this->pathIsDirectoryAsRoot($destData)) {
                Log::warning('Skipping volume data copy; dest volume _data missing', [
                    'volume' => $entry,
                    'dest' => $destData,
                ]);

                continue;
            }
            $this->runVolumeProcess(
                [
                    'sudo', 'rsync', '-a', '--delete',
                    rtrim($srcData, '/').'/',
                    rtrim($destData, '/').'/',
                ],
                "Failed to rsync volume data for '{$entry}'"
            );
            $copied++;
        }

        return $copied;
    }

    public function copyVolumeDataToIncoming(string $sourceHomeDir): int
    {
        $srcVolumes = rtrim($sourceHomeDir, '/').'/docker/volumes';
        $destVolumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';

        if (! $this->pathIsDirectoryAsRoot($srcVolumes) || ! $this->pathIsDirectoryAsRoot($destVolumes)) {
            return 0;
        }

        $copied = 0;
        foreach ($this->listDockerVolumeEntriesAsRoot($srcVolumes) as $entry) {
            $srcData = "{$srcVolumes}/{$entry}/_data";
            $destVolumeDir = "{$destVolumes}/{$entry}";
            $destIncoming = "{$destVolumeDir}/_data.incoming";

            if (! $this->pathIsDirectoryAsRoot($srcData)) {
                continue;
            }
            if (! $this->pathIsDirectoryAsRoot($destVolumeDir)) {
                Log::warning('Skipping push volume data copy; dest volume missing', [
                    'volume' => $entry,
                    'dest' => $destVolumeDir,
                ]);

                continue;
            }

            $this->runVolumeProcess(
                ['sudo', 'mkdir', '-p', $destIncoming],
                "Failed to create incoming volume directory for '{$entry}'"
            );
            $this->runVolumeProcess(
                [
                    'sudo', 'rsync', '-a', '--delete',
                    rtrim($srcData, '/').'/',
                    rtrim($destIncoming, '/').'/',
                ],
                "Failed to rsync volume data for '{$entry}'"
            );
            $copied++;
        }

        return $copied;
    }

    public function swapIncomingVolumes(): void
    {
        $destVolumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';
        if (! $this->pathIsDirectoryAsRoot($destVolumes)) {
            return;
        }

        foreach ($this->listDockerVolumeEntriesAsRoot($destVolumes) as $entry) {
            $incomingData = "{$destVolumes}/{$entry}/_data.incoming";
            if (! $this->pathIsDirectoryAsRoot($incomingData)) {
                continue;
            }

            $currentData = "{$destVolumes}/{$entry}/_data";
            $backupData = "{$destVolumes}/{$entry}/_data.pre-push";

            if ($this->pathIsDirectoryAsRoot($currentData)) {
                $this->runVolumeProcess(
                    ['sudo', 'mv', $currentData, $backupData],
                    "Failed to move volume '{$entry}' aside before swap"
                );
            }
            $this->runVolumeProcess(
                ['sudo', 'mv', $incomingData, $currentData],
                "Failed to swap incoming volume data for '{$entry}'"
            );
        }
    }

    /**
     * Undo swapIncomingVolumes(): every volume whose data was moved aside gets
     * it back, and data that was only staged is dropped.
     */
    public function restoreVolumeBackups(): void
    {
        $destVolumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';
        if (! $this->pathIsDirectoryAsRoot($destVolumes)) {
            return;
        }

        foreach ($this->listDockerVolumeEntriesAsRoot($destVolumes) as $entry) {
            $this->restoreVolumeBackup("{$destVolumes}/{$entry}", $entry);
        }
    }

    private function restoreVolumeBackup(string $volumeDir, string $entry): void
    {
        $backupData = "{$volumeDir}/_data.pre-push";
        if ($this->pathIsDirectoryAsRoot($backupData)) {
            $this->runVolumeProcess(
                ['sudo', 'rm', '-rf', "{$volumeDir}/_data"],
                "Failed to remove pushed volume data for '{$entry}'"
            );
            $this->runVolumeProcess(
                ['sudo', 'mv', $backupData, "{$volumeDir}/_data"],
                "Failed to restore volume data for '{$entry}'"
            );
        }

        if ($this->pathIsDirectoryAsRoot("{$volumeDir}/_data.incoming")) {
            $this->runVolumeProcess(
                ['sudo', 'rm', '-rf', "{$volumeDir}/_data.incoming"],
                "Failed to remove staged volume data for '{$entry}'"
            );
        }
    }

    public function discardVolumeBackups(): void
    {
        $destVolumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';
        if (! $this->pathIsDirectoryAsRoot($destVolumes)) {
            return;
        }

        foreach ($this->listDockerVolumeEntriesAsRoot($destVolumes) as $entry) {
            foreach (['_data.pre-push', '_data.incoming'] as $suffix) {
                $path = "{$destVolumes}/{$entry}/{$suffix}";
                if ($this->pathIsDirectoryAsRoot($path)) {
                    $this->runVolumeProcess(
                        ['sudo', 'rm', '-rf', $path],
                        "Failed to remove volume backup '{$entry}/{$suffix}'"
                    );
                }
            }
        }
    }

    public function countVolumeDataDirs(): int
    {
        $volumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';
        if (! $this->pathIsDirectoryAsRoot($volumes)) {
            return 0;
        }

        $count = 0;
        foreach ($this->listDockerVolumeEntriesAsRoot($volumes) as $entry) {
            $data = "{$volumes}/{$entry}/_data";
            if ($this->pathIsDirectoryAsRoot($data)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $cmd
     */
    private function runVolumeProcess(array $cmd, string $message): void
    {
        $process = $this->project->system()->runProcess($cmd);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException($message.': '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * The named volumes under the account's docker/volumes. Anonymous volumes
     * (64 hex characters) are left out: an image's own VOLUME the compose file
     * never names, which a copy has nowhere to put and the image recreates.
     *
     * @return list<string>
     */
    private function listDockerVolumeEntriesAsRoot(string $volumesDir): array
    {
        $process = $this->project->system()->runProcess(['sudo', 'ls', '-1', $volumesDir]);
        if (! $process->isSuccessful()) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '' || $entry === 'metadata.db' || self::isAnonymousVolume($entry)) {
                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    private static function isAnonymousVolume(string $entry): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $entry) === 1;
    }

    private function pathIsDirectoryAsRoot(string $path): bool
    {
        return $this->project->system()->runProcess(['sudo', 'test', '-d', $path])->isSuccessful();
    }
}
