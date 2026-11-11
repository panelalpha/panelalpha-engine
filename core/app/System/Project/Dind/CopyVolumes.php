<?php

namespace App\System\Project\Dind;

use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Pause/resume and the named volumes' copy for clone/push, through the accounts' own daemons ({@see VolumeArchive}):
 * staged as `<volume>/_data.incoming`, with a push keeping this account's data as `_data.pre-push` until it commits.
 */
final class CopyVolumes
{
    /** The home entry a copy stages volumes in, which a push's home swap leaves where it is. */
    public const STAGING = '.panelalpha-volumes';

    private const INCOMING = '_data.incoming';

    private const PRE_PUSH = '_data.pre-push';

    private const INSPECT_TIMEOUT_SECONDS = 30;

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
     * The copied source starts again as it was stopped: `up` would rewrite its run file first and apply what
     * was stored for later, such as env vars.
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

    /**
     * Each named volume of $source that this account has, its data put in place of this one's; how many were. Nothing
     * staged is left, whether or not the copy got through.
     */
    public function copyVolumeDataFrom(self $source): int
    {
        try {
            return $this->putInPlaceFrom($source);
        } finally {
            $this->removeStaging();
        }
    }

    private function putInPlaceFrom(self $source): int
    {
        $copied = 0;
        foreach ($this->volumesToCopyFrom($source) as $volume) {
            $this->stageVolume($source, $volume);
            $this->step(
                fn () => $this->archive()->replaceVolume($volume, $this->staged($volume, self::INCOMING)),
                "Failed to rsync volume data for '{$volume}'",
            );
            $this->runVolumeProcess(
                Privileged::files()->removeTree($this->staged($volume)),
                "Failed to remove staged volume data for '{$volume}'",
            );
            $copied++;
        }

        return $copied;
    }

    /** Each named volume of $source that this account has, staged beside its home's entries; how many were. */
    public function copyVolumeDataToIncoming(self $source): int
    {
        $copied = 0;
        foreach ($this->volumesToCopyFrom($source) as $volume) {
            $this->stageVolume($source, $volume);
            $copied++;
        }

        return $copied;
    }

    /** Each staged volume's data put in place, this account's own archived beside it first. */
    public function swapIncomingVolumes(): void
    {
        foreach ($this->stagedVolumes() as $volume) {
            $incoming = $this->staged($volume, self::INCOMING);
            if (! $this->pathIsFileAsRoot($incoming)) {
                continue;
            }
            $this->step(
                fn () => $this->archive()->tarVolume($volume, $this->staged($volume, self::PRE_PUSH)),
                "Failed to move volume '{$volume}' aside before swap",
            );
            $this->step(
                fn () => $this->archive()->replaceVolume($volume, $incoming),
                "Failed to swap incoming volume data for '{$volume}'",
            );
            $this->runVolumeProcess(Privileged::files()->removeIfExists($incoming), "Failed to swap incoming volume data for '{$volume}'");
        }
    }

    /**
     * Undo swapIncomingVolumes(): data archived aside comes back, and data that was only staged is dropped. Every volume
     * is tried; one that could not be restored keeps its archive, and the failures are thrown together.
     */
    public function restoreVolumeBackups(): void
    {
        $failures = [];
        foreach ($this->stagedVolumes() as $volume) {
            try {
                $this->restoreVolumeBackup($volume);
            } catch (\RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(implode('; ', $failures));
        }
        $this->removeStaging();
    }

    private function restoreVolumeBackup(string $volume): void
    {
        $prePush = $this->staged($volume, self::PRE_PUSH);
        if ($this->pathIsFileAsRoot($prePush)) {
            $this->step(
                fn () => $this->archive()->replaceVolume($volume, $prePush),
                "Failed to restore volume data for '{$volume}'",
            );
        }

        $this->runVolumeProcess(
            Privileged::files()->removeTree($this->staged($volume)),
            "Failed to remove staged volume data for '{$volume}'",
        );
    }

    public function discardVolumeBackups(): void
    {
        foreach ($this->stagedVolumes() as $volume) {
            foreach ([self::PRE_PUSH, self::INCOMING] as $suffix) {
                $path = $this->staged($volume, $suffix);
                if ($this->pathIsFileAsRoot($path)) {
                    $this->runVolumeProcess(
                        Privileged::files()->removeIfExists($path),
                        "Failed to remove volume backup '{$volume}/{$suffix}'"
                    );
                }
            }
        }
        $this->removeStaging();
    }

    /**
     * The named volumes a copy carries, as this account's daemon lists them; 0 when it cannot say, and the copy that
     * follows fails with the reason.
     */
    public function countVolumeDataDirs(): int
    {
        try {
            return count($this->namedVolumes());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A warning per anonymous volume holding data that a container mounts, which a copy leaves out, named by
     * where it is mounted; every one holding data when the containers cannot be read.
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
     * Where each anonymous volume is mounted, read from the account's own containers; null when they cannot be read.
     *
     * @return array<string, list<string>>|null
     */
    private function anonymousMounts(): ?array
    {
        try {
            $daemon = $this->project->accountDaemon();
            $inspected = $daemon->containers($daemon->containerIds(true, [], self::INSPECT_TIMEOUT_SECONDS), self::INSPECT_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return in_array(null, $inspected, true) ? null : self::mountsOf($inspected);
    }

    /**
     * `<service>:<mount point>` of each anonymous volume the containers mount, from their inspects.
     *
     * @param  list<array<string, mixed>>  $inspected
     * @return array<string, list<string>>
     */
    private static function mountsOf(array $inspected): array
    {
        $mounts = [];
        foreach ($inspected as $container) {
            $service = trim((string) ($container['Config']['Labels']['com.docker.compose.service'] ?? ''));
            foreach (is_array($container['Mounts'] ?? null) ? $container['Mounts'] : [] as $mount) {
                $volume = (string) ($mount['Name'] ?? '');
                $destination = (string) ($mount['Destination'] ?? '');
                if (self::isAnonymousVolume($volume) && $destination !== '') {
                    $mounts[$volume][] = $service === '' ? $destination : $service.':'.$destination;
                }
            }
        }

        return $mounts;
    }

    private function holdsDataAsRoot(string $path): bool
    {
        $process = $this->project->system()->runProcess(Privileged::files()->firstEntry($path));

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
     * The named volumes of the account's daemon, not the anonymous ones a copy has nowhere to put and the image
     * recreates; {@see anonymousVolumeWarnings()} names those that held data.
     *
     * @return list<string>
     */
    private function namedVolumes(): array
    {
        return array_values(array_filter(
            $this->project->accountDaemon()->volumeNames(),
            static fn (string $volume): bool => ! self::isAnonymousVolume($volume),
        ));
    }

    /**
     * The named volumes of $source this account has too; one it lacks is skipped and logged.
     *
     * @return list<string>
     */
    private function volumesToCopyFrom(self $source): array
    {
        $named = $source->namedVolumes();
        $present = $named === [] ? [] : $this->project->accountDaemon()->volumeNames();
        $volumes = [];
        foreach ($named as $volume) {
            if (in_array($volume, $present, true)) {
                $volumes[] = $volume;

                continue;
            }
            Log::warning('Skipping volume data copy; dest volume missing', [
                'volume' => $volume,
                'dest' => $this->project->username(),
            ]);
        }

        return $volumes;
    }

    /** $volume of $source archived by a tar container of $source's daemon, into this home's staging. */
    private function stageVolume(self $source, string $volume): void
    {
        $this->runVolumeProcess(
            Privileged::files()->makeDirectories($this->staged($volume)),
            "Failed to create incoming volume directory for '{$volume}'"
        );
        $this->step(
            fn () => $source->archive()->tarVolume($volume, $this->staged($volume, self::INCOMING)),
            "Failed to rsync volume data for '{$volume}'",
        );
    }

    /** @return list<string> */
    private function stagedVolumes(): array
    {
        return $this->pathIsDirectoryAsRoot($this->staging()) ? $this->volumeEntriesAsRoot($this->staging()) : [];
    }

    private function removeStaging(): void
    {
        if ($this->pathIsDirectoryAsRoot($this->staging())) {
            $this->runVolumeProcess(Privileged::files()->removeTree($this->staging()), "Failed to remove '".self::STAGING."'");
        }
    }

    private function staging(): string
    {
        return rtrim($this->project->homeDirPath(), '/').'/'.self::STAGING;
    }

    private function staged(string $volume, ?string $file = null): string
    {
        return $this->staging().'/'.$volume.($file === null ? '' : '/'.$file);
    }

    private function archive(): VolumeArchive
    {
        return new VolumeArchive($this->project);
    }

    /** $step, its failure thrown as $message and the reason. */
    private function step(callable $step, string $message): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            throw new \RuntimeException($message.': '.$e->getMessage(), 0, $e);
        }
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
        $process = $this->project->system()->runProcess(Privileged::files()->listNames($volumesDir));
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
        return $this->project->system()->runProcess(Privileged::files()->isDirectory($path))->isSuccessful();
    }

    private function pathIsFileAsRoot(string $path): bool
    {
        return $this->project->system()->runProcess(Privileged::files()->isFile($path))->isSuccessful();
    }
}
