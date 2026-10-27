<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Pause/resume and DinD volume staging for clone/push (not part of the public Project contract).
 */
final class CopyVolumes
{
    private const INSPECT_TIMEOUT_SECONDS = 30;

    /** `docker inspect` per container: its compose service, then each `<volume>=<mount point>` tab-separated. */
    private const MOUNTS_FORMAT = "{{index .Config.Labels \"com.docker.compose.service\"}}|{{range .Mounts}}{{.Name}}={{.Destination}}\t{{end}}";

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
        $this->resumeWith('up');
    }

    /**
     * The copied source starts again as it was stopped: `up` would rewrite its
     * run file first and apply what was stored for later, such as env vars.
     */
    public function resumeSourceAfterCopy(): void
    {
        $this->resumeWith('start');
    }

    private function resumeWith(string $action): void
    {
        try {
            $this->containers->projectAction($action);
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
     * One warning for each anonymous volume of this account that holds data
     * and is mounted by one of its containers, which a copy leaves out, named
     * by where it is mounted. When the containers cannot be read, every
     * anonymous volume holding data is named.
     *
     * @return list<string>
     */
    public function anonymousVolumeWarnings(): array
    {
        $volumes = rtrim($this->project->homeDirPath(), '/').'/docker/volumes';
        if (! $this->pathIsDirectoryAsRoot($volumes)) {
            return [];
        }
        $withData = array_values(array_filter(
            $this->anonymousVolumeEntriesAsRoot($volumes),
            fn (string $volume): bool => $this->holdsDataAsRoot("{$volumes}/{$volume}/_data"),
        ));
        $mounts = $withData === [] ? [] : $this->anonymousMounts();
        $leftOut = $mounts === null ? $withData : array_values(array_intersect($withData, array_keys($mounts)));

        return array_map(
            static fn (string $volume): string => self::anonymousVolumeWarning($volume, $mounts[$volume] ?? []),
            $leftOut,
        );
    }

    /**
     * @param  list<string>  $mounts  `<service>:<mount point>` each
     */
    private static function anonymousVolumeWarning(string $volume, array $mounts): string
    {
        $where = $mounts === [] ? '' : ' ('.implode(', ', $mounts).')';

        return 'Anonymous volume '.substr($volume, 0, 12).$where.' holds data that was not copied. '
            .'Name it under the service\'s volumes: in the compose file to have it copied.';
    }

    /**
     * Where each anonymous volume is mounted, read from the account's own
     * containers; null when they cannot be read.
     *
     * @return array<string, list<string>>|null
     */
    private function anonymousMounts(): ?array
    {
        $script = 'ids=$(docker ps -aq) || exit 1; [ -n "$ids" ] || exit 0; docker inspect --format '
            .escapeshellarg(self::MOUNTS_FORMAT).' $ids';
        try {
            return self::parseMounts(
                $this->project->shell()->execQuiet(['bash', '-c', $script], [], self::INSPECT_TIMEOUT_SECONDS)
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseMounts(string $inspect): array
    {
        $mounts = [];
        foreach (preg_split('/\R/', trim($inspect)) ?: [] as $line) {
            [$service, $list] = array_pad(explode('|', $line, 2), 2, '');
            foreach (explode("\t", $list) as $mount) {
                [$volume, $destination] = array_pad(explode('=', $mount, 2), 2, '');
                if (self::isAnonymousVolume($volume) && $destination !== '') {
                    $mounts[$volume][] = trim($service) === '' ? $destination : trim($service).':'.$destination;
                }
            }
        }

        return $mounts;
    }

    private function holdsDataAsRoot(string $path): bool
    {
        $process = $this->project->system()->runProcess(['sudo', 'find', $path, '-mindepth', '1', '-maxdepth', '1', '-print', '-quit']);

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
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
     * {@see anonymousVolumeWarnings()} names the ones that held data.
     *
     * @return list<string>
     */
    private function listDockerVolumeEntriesAsRoot(string $volumesDir): array
    {
        return array_values(array_filter(
            $this->volumeEntriesAsRoot($volumesDir),
            static fn (string $entry): bool => ! self::isAnonymousVolume($entry),
        ));
    }

    /**
     * @return list<string>
     */
    private function anonymousVolumeEntriesAsRoot(string $volumesDir): array
    {
        return array_values(array_filter($this->volumeEntriesAsRoot($volumesDir), self::isAnonymousVolume(...)));
    }

    /**
     * @return list<string>
     */
    private function volumeEntriesAsRoot(string $volumesDir): array
    {
        $process = $this->project->system()->runProcess(['sudo', 'ls', '-1', $volumesDir]);
        if (! $process->isSuccessful()) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '' || $entry === 'metadata.db') {
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
