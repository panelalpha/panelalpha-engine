<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\NamedVolumes;
use App\Support\JobBudget;
use App\System\Project\Dind as DindProject;
use RuntimeException;

/**
 * Named-volume archive for backup/restore (docker run alpine tar).
 * Distinct from {@see CopyVolumes} which rsyncs volume _data between accounts.
 */
final class VolumeArchive
{
    /** The job's own budget, so a long tar fails here and cleans up before the job's alarm kills the worker. */
    private const COMPOSE_TIMEOUT = JobBudget::COMMAND_SECONDS;

    private const VOLUME_TAR_IMAGE = 'alpine:3.20';

    private const VOLUME_SCRATCH_SUBDIR = '.panelalpha-backup-scratch';

    public function __construct(
        private DindProject $project,
    ) {}

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

        $this->project->shell()->execAsUser([
            'docker',
            'run',
            '--rm',
            '-v',
            $dockerName.':/data:ro',
            '-v',
            $scratchDir.':/out',
            self::VOLUME_TAR_IMAGE,
            'tar',
            '-C',
            '/data',
            '-czf',
            '/out/'.$basename,
            '.',
        ], [], self::COMPOSE_TIMEOUT);

        $this->project->system()->execOnHost(['mv', '-f', $scratchFile, $dest]);
        $this->cleanScratchDir($scratchDir);
    }

    public function ensureVolume(string $dockerName): void
    {
        $this->assertDockerVolumeName($dockerName);

        try {
            $this->project->shell()->execAsUser(
                ['docker', 'volume', 'create', $dockerName],
                [],
                120,
            );
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
        return str_replace("\r", '', <<<'SH'
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
SH);
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
        $this->project->system()->execOnHost(['cp', $archivePath, $scratchArchive]);

        $this->project->shell()->execAsUser([
            'docker',
            'run',
            '--rm',
            '-v',
            $dockerName.':/data',
            '-v',
            $scratchArchive.':/archive:ro',
            self::VOLUME_TAR_IMAGE,
            'sh',
            '-c',
            self::volumeRestoreScript(),
        ], [], self::COMPOSE_TIMEOUT);

        $this->project->system()->execOnHost(['rm', '-f', $scratchArchive]);
        $this->cleanScratchDir($scratchDir);
    }

    public function rollbackVolume(string $dockerName): void
    {
        $this->assertDockerVolumeName($dockerName);

        $this->project->shell()->execAsUser([
            'docker',
            'run',
            '--rm',
            '-v',
            $dockerName.':/data',
            self::VOLUME_TAR_IMAGE,
            'sh',
            '-c',
            self::volumeRollbackScript(),
        ], [], self::COMPOSE_TIMEOUT);
    }

    private function inspectDockerVolumeName(string $dockerName): string
    {
        $output = $this->project->shell()->execAsUser(
            ['docker', 'volume', 'inspect', '-f', '{{.Name}}', $dockerName],
            [],
            60,
        );
        $name = trim($output);
        if ($name === '') {
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
        $this->project->system()->execOnHost(['mkdir', '-p', $path]);
    }

    private function cleanScratchDir(string $path): void
    {
        if (! $this->pathExistsOnHost($path)) {
            return;
        }

        $this->project->system()->execOnHost(['rm', '-rf', $path]);
    }

    private function pathExistsOnHost(string $path): bool
    {
        return $this->project->system()->runProcess([
            'sudo',
            'test',
            '-e',
            $path,
        ])->getExitCode() === 0;
    }
}
