<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\NamedVolumes;
use App\Support\JobBudget;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Named-volume archive for backup/restore and for the copies of {@see CopyVolumes} (an alpine container running tar).
 */
final class VolumeArchive
{
    /** The cap a volume archive runs on when the caller passes no timeout. */
    private const COMPOSE_TIMEOUT = JobBudget::COMMAND_SECONDS;

    private const VOLUME_TAR_IMAGE = 'alpine:3.20';

    private const VOLUME_SCRATCH_SUBDIR = '.panelalpha-backup-scratch';

    public function __construct(
        private DindProject $project,
        private ?int $timeout = null,
    ) {}

    /** The job's step budget when one is passed; one command's cap otherwise. */
    private function timeout(): int
    {
        return $this->timeout ?? self::COMPOSE_TIMEOUT;
    }

    /**
     * @param  array{services?: mixed, volumes?: mixed}  $compose
     * @return list<array{name: string, docker_name: string}>
     */
    public static function volumesFromCompose(array $compose): array
    {
        $services = $compose['services'] ?? [];
        $declared = $compose['volumes'] ?? [];
        if (! is_array($services) || ! is_array($declared)) {
            return [];
        }

        $used = NamedVolumes::usedBy($services, $declared);
        $volumes = [];
        foreach (array_keys($used) as $name) {
            $volumes[] = [
                'name' => $name,
                'docker_name' => self::declaredDockerName($name, $declared[$name] ?? null),
            ];
        }

        return $volumes;
    }

    /** The volume's own `name:` when its declaration sets one, else its compose key. */
    private static function declaredDockerName(int|string $name, mixed $entry): int|string
    {
        if (is_array($entry) && isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== '') {
            return $entry['name'];
        }

        return $name;
    }

    /**
     * @return list<array{name: string, docker_name: string}>
     */
    public function namedVolumes(): array
    {
        $output = $this->project->shell()->execAsUser(
            $this->project->userAppComposeCommand(['config', '--format', 'json']),
            [],
            120,
        );

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to parse inner compose config JSON');
        }

        $volumes = self::volumesFromCompose($decoded);
        foreach ($volumes as $index => $volume) {
            $volumes[$index]['docker_name'] = $this->inspectDockerVolumeName($volume['docker_name']);
        }

        return $volumes;
    }

    public function tarVolume(string $dockerName, string $dest): void
    {
        $scratchDir = $this->userPath(self::VOLUME_SCRATCH_SUBDIR);
        $basename = basename($dest);
        $scratchFile = rtrim($scratchDir, '/').'/'.$basename;

        $this->ensureDirectoryOnHost($scratchDir);

        $this->runTarImage(
            [[$dockerName, '/data', 'ro'], [$scratchDir, '/out', 'rw']],
            ['tar', '-C', '/data', '-czf', '/out/'.$basename, '.'],
        );

        $this->project->system()->exec(Privileged::hostFiles()->moveOverwriting($scratchFile, $dest));
        $this->cleanScratchDir($scratchDir);
    }

    public function ensureVolume(string $dockerName): void
    {
        $this->assertDockerVolumeName($dockerName);

        try {
            $this->project->accountDaemon()->createVolume($dockerName, 120);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    /**
     * Ash-compatible restore-beside script for named volumes.
     * Leaves .panelalpha-aside in place if anything fails before the final cleanup.
     */
    public static function volumeRestoreScript(): string
    {
        return str_replace("\r", '', self::volumeRestoreShell());
    }

    private static function volumeRestoreShell(): string
    {
        return <<<'SH'
set -e
cd /data
if [ -d .panelalpha-aside ]; then
  echo "leftover .panelalpha-aside exists; refusing to overwrite" >&2
  exit 1
fi
rm -rf .panelalpha-incoming
mkdir .panelalpha-incoming
tar -xzf /archive -C .panelalpha-incoming
mkdir .panelalpha-aside
for entry in * .*; do
  [ -e "$entry" ] || continue
  case "$entry" in
    .|..) continue ;;
    .panelalpha-incoming|.panelalpha-aside) continue ;;
  esac
  mv "$entry" .panelalpha-aside/
done
for entry in .panelalpha-incoming/* .panelalpha-incoming/.*; do
  [ -e "$entry" ] || continue
  base=$(basename "$entry")
  case "$base" in
    .|..) continue ;;
  esac
  mv "$entry" .
done
rm -rf .panelalpha-incoming
rm -rf .panelalpha-aside
SH;
    }

    /**
     * Ash-compatible rollback of a failed volume restore from .panelalpha-aside.
     */
    public static function volumeRollbackScript(): string
    {
        return str_replace("\r", '', <<<'SH'
set -e
cd /data
if [ ! -d .panelalpha-aside ]; then
  echo "no .panelalpha-aside to roll back" >&2
  exit 1
fi
rm -rf .panelalpha-failed
mkdir .panelalpha-failed
for entry in * .*; do
  [ -e "$entry" ] || continue
  case "$entry" in
    .|..) continue ;;
    .panelalpha-aside|.panelalpha-failed|.panelalpha-incoming) continue ;;
  esac
  mv "$entry" .panelalpha-failed/
done
for entry in .panelalpha-aside/* .panelalpha-aside/.*; do
  [ -e "$entry" ] || continue
  base=$(basename "$entry")
  case "$base" in
    .|..) continue ;;
  esac
  mv "$entry" .
done
rm -rf .panelalpha-aside .panelalpha-failed .panelalpha-incoming
SH);
    }

    public function restoreVolume(string $dockerName, string $archivePath): void
    {
        $this->assertDockerVolumeName($dockerName);

        $scratchDir = $this->userPath(self::VOLUME_SCRATCH_SUBDIR);
        $basename = basename($archivePath);
        $scratchArchive = rtrim($scratchDir, '/').'/'.$basename;

        $this->ensureDirectoryOnHost($scratchDir);
        $this->project->system()->exec(Privileged::hostFiles()->copy($archivePath, $scratchArchive));

        $this->runTarImage(
            [[$dockerName, '/data', 'rw'], [$scratchArchive, '/archive', 'ro']],
            ['sh', '-c', self::volumeRestoreScript()],
        );

        $this->project->system()->exec(Privileged::hostFiles()->removeIfExists($scratchArchive));
        $this->cleanScratchDir($scratchDir);
    }

    /** The volume's data replaced by an archive already in the account's home, as a copy stages it. */
    public function replaceVolume(string $dockerName, string $archiveInHome): void
    {
        $this->assertDockerVolumeName($dockerName);

        $this->runTarImage(
            [[$dockerName, '/data', 'rw'], [$archiveInHome, '/archive', 'ro']],
            ['sh', '-c', self::volumeReplaceScript()],
        );
    }

    /** Ash-compatible: everything in the volume removed, then the archive extracted into it. */
    public static function volumeReplaceScript(): string
    {
        return str_replace("\r", '', <<<'SH'
set -e
cd /data
find . -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
tar -xzf /archive -C /data
SH);
    }

    public function rollbackVolume(string $dockerName): void
    {
        $this->assertDockerVolumeName($dockerName);

        $this->runTarImage([[$dockerName, '/data', 'rw']], ['sh', '-c', self::volumeRollbackScript()]);
    }

    /**
     * The tar image run once and removed; like {@see ShellOperations::execAsUser()}, refused once a deploy is
     * cancelled and its output written to a running deploy's log.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $mounts
     * @param  list<string>  $argv
     */
    private function runTarImage(array $mounts, array $argv): void
    {
        $logger = $this->project->shell()->logger();
        $logger?->throwIfCancelled();
        $output = $this->project->accountDaemon()->runOnce(self::VOLUME_TAR_IMAGE, $mounts, $argv, $this->timeout());
        $logger?->writeProcessBuffer(Process::OUT, $output);
        $logger?->flushBuffers();
    }

    private function inspectDockerVolumeName(string $dockerName): string
    {
        $name = $this->project->accountDaemon()->volume($dockerName, 60)['Name'] ?? '';
        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Docker volume inspect returned empty name for: '.$dockerName);
        }

        return $name;
    }

    private function assertDockerVolumeName(string $name): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $name)) {
            throw new RuntimeException('Invalid docker volume name');
        }
    }

    private function userPath(string $relative): string
    {
        return rtrim($this->project->homeDirPath(), '/').'/'.ltrim($relative, '/');
    }

    private function ensureDirectoryOnHost(string $path): void
    {
        $this->project->system()->exec(Privileged::hostFiles()->makeDirectories($path));
    }

    private function cleanScratchDir(string $path): void
    {
        if (! $this->pathExistsOnHost($path)) {
            return;
        }

        $this->project->system()->exec(Privileged::hostFiles()->removeTree($path));
    }

    private function pathExistsOnHost(string $path): bool
    {
        return $this->project->system()->runProcess(Privileged::files()->exists($path))->getExitCode() === 0;
    }
}
