<?php

namespace Tests\Unit\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup;

/**
 * Test subclass that overrides host/storage forwards without a Host type.
 */
final class BackupDouble extends Backup
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        ProjectAggregate $project,
        BackupRecord $record,
        private readonly ?BackupStorage $storageDouble = null,
        /** @var list<array{name: string, docker_name: string}> */
        private array $volumes = [['name' => 'dbdata', 'docker_name' => 'proj_dbdata']],
        /** @var list<string> */
        private array $databases = ['alice_app'],
        private bool $composeStartThrows = false,
        private bool $swapProjectThrows = false,
        private bool $rollbackProjectThrows = false,
        private bool $restoreVolumeThrows = false,
    ) {
        parent::__construct($project, $record);
    }

    protected function resolveStorage(BackupContainer $container): BackupStorage
    {
        return $this->storageDouble ?? parent::resolveStorage($container);
    }

    protected function composeStop(): void
    {
        $this->calls[] = 'composeStop';
    }

    protected function composeStart(): void
    {
        $this->calls[] = 'composeStart';
        if ($this->composeStartThrows) {
            throw new \RuntimeException('compose start failed');
        }
    }

    protected function namedVolumes(): array
    {
        return $this->volumes;
    }

    protected function tarVolume(string $dockerName, string $dest): void
    {
        $this->calls[] = 'tarVolume';
        $this->writeStubFile($dest);
    }

    protected function ensureVolume(string $dockerName): void
    {
        $this->calls[] = 'ensureVolume:' . $dockerName;
    }

    protected function restoreVolume(string $dockerName, string $archivePath): void
    {
        $this->calls[] = 'restoreVolume:' . $dockerName;
        if ($this->restoreVolumeThrows) {
            throw new \RuntimeException('volume restore failed');
        }
    }

    protected function rollbackVolume(string $dockerName): void
    {
        $this->calls[] = 'rollbackVolume:' . $dockerName;
    }

    protected function databaseNames(): array
    {
        return $this->databases;
    }

    protected function dumpDatabase(string $name, string $dest): void
    {
        $this->calls[] = 'dumpDatabase';
        $this->writeStubFile($dest);
    }

    protected function restoreDatabase(string $name, string $sqlGzPath): void
    {
        $this->calls[] = 'restoreDatabase:' . $name;
    }

    protected function tarProject(string $dest): void
    {
        $this->calls[] = 'tarProject';
        $this->writeStubFile($dest);
    }

    protected function extractProjectArchive(string $archivePath, string $incomingDir): void
    {
        $this->calls[] = 'extractProjectArchive:' . $incomingDir;
    }

    protected function swapProject(string $incomingDir, string $asideDir): void
    {
        $this->calls[] = 'swapProject:' . $incomingDir . ':' . $asideDir;
        if ($this->swapProjectThrows) {
            throw new \RuntimeException('swap failed');
        }
    }

    protected function rollbackProject(string $asideDir): void
    {
        $this->calls[] = 'rollbackProject:' . $asideDir;
        if ($this->rollbackProjectThrows) {
            throw new \RuntimeException('rollback failed');
        }
    }

    protected function discardProjectAside(string $asideDir): void
    {
        $this->calls[] = 'discardProjectAside:' . $asideDir;
    }

    private function writeStubFile(string $dest): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dest, 'stub-' . basename($dest));
    }
}
