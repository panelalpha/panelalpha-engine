<?php

namespace Tests\Integration\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Support\JobDeadline;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectContent;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Backup\ProjectFiles;
use App\System\Project\Backup\ProjectVolumes;
use App\System\Services\Mysql\ImportAccount;

/**
 * Test subclass whose stack, content and storage are doubles recording one shared call list.
 */
final class BackupDouble extends Backup
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<?int> the timeout each restoreDatabase() call was given, null = the step window */
    public array $databaseRestoreTimeouts = [];

    /** @var array<string, string> database name => the dump that was loaded into it */
    public array $restoredDumps = [];

    /** @var array<string, array{from: string, account: string}> a copied database => the account it was loaded as */
    public array $copiedDatabases = [];

    private ProjectContent $contentDouble;

    private AppStack $stackDouble;

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
        /** Called from composeStop(), to observe state during the stopped window. */
        private mixed $onComposeStop = null,
        private bool $swapHomeThrows = false,
        private bool $mergeIntoHomeThrows = false,
        private bool $restoreDatabaseThrows = false,
        private ?JobDeadline $deadline = null,
    ) {
        parent::__construct($project, $record, $deadline);

        $doubleDeadline = $deadline ?? JobDeadline::startingNow();
        $this->stackDouble = $this->stackDoubleFor($project);
        $this->contentDouble = new ProjectContent(
            $this->filesDoubleFor($project, $doubleDeadline),
            $this->homeDoubleFor($project, $doubleDeadline),
            $this->volumesDoubleFor($project, $doubleDeadline),
            $this->databasesDoubleFor($project, $doubleDeadline),
        );
    }

    protected function resolveStorage(BackupContainer $container): BackupStorage
    {
        return $this->storageDouble ?? parent::resolveStorage($container);
    }

    protected function content(): ProjectContent
    {
        return $this->contentDouble;
    }

    protected function stack(): AppStack
    {
        return $this->stackDouble;
    }

    public function composeStop(): void
    {
        $this->calls[] = 'composeStop';
        if (is_callable($this->onComposeStop)) {
            ($this->onComposeStop)();
        }
    }

    public function composeStart(): void
    {
        $this->calls[] = 'composeStart';
        if ($this->composeStartThrows) {
            throw new \RuntimeException('compose start failed');
        }
    }

    /** @return list<array{name: string, docker_name: string}> */
    public function namedVolumes(): array
    {
        return $this->volumes;
    }

    public function tarVolume(string $dockerName, string $dest): void
    {
        $this->calls[] = 'tarVolume';
        $this->writeStubFile($dest);
    }

    public function ensureVolume(string $dockerName): void
    {
        $this->calls[] = 'ensureVolume:' . $dockerName;
    }

    public function restoreVolume(string $dockerName, string $archivePath): void
    {
        $this->calls[] = 'restoreVolume:' . $dockerName;
        if ($this->restoreVolumeThrows) {
            throw new \RuntimeException('volume restore failed');
        }
    }

    public function rollbackVolume(string $dockerName): void
    {
        $this->calls[] = 'rollbackVolume:' . $dockerName;
    }

    /** @return list<string> */
    public function databaseNames(): array
    {
        return $this->databases;
    }

    public function dumpDatabase(string $name, string $dest): void
    {
        $this->calls[] = 'dumpDatabase';
        $this->writeStubFile($dest);
    }

    public function restoreDatabase(string $name, string $sqlGzPath, ?int $timeout = null): void
    {
        $this->calls[] = 'restoreDatabase:' . $name;
        $this->databaseRestoreTimeouts[] = $timeout;
        $this->restoredDumps[$name] = $sqlGzPath;
        if ($this->restoreDatabaseThrows) {
            $this->restoreDatabaseThrows = false;
            throw new \RuntimeException('database restore failed');
        }
    }

    public function restoreCopiedDatabase(string $sourceName, string $name, string $sqlGzPath, ImportAccount $account): void
    {
        $this->copiedDatabases[$name] = ['from' => $sourceName, 'account' => $account->user];
        $this->restoreDatabase($name, $sqlGzPath);
    }

    public function tarProject(string $dest): void
    {
        $this->calls[] = 'tarProject';
        $this->writeStubFile($dest);
    }

    public function tarHome(string $dest): void
    {
        $this->calls[] = 'tarHome';
        $this->writeStubFile($dest);
    }

    public function extractHomeArchive(string $archivePath, string $targetDir): void
    {
        $this->calls[] = 'extractHomeArchive:' . $targetDir;
    }

    public function mergeIntoHome(string $archivePath, string $incomingDir): void
    {
        $this->calls[] = 'mergeIntoHome:' . $incomingDir;
        if ($this->mergeIntoHomeThrows) {
            throw new \RuntimeException('home merge failed');
        }
    }

    public function removeHomeEntry(string $entry): void
    {
        $this->calls[] = 'removeHomeEntry:' . $entry;
    }

    /** @param list<string> $skipEntries */
    public function swapHome(string $incomingDir, string $asideDir, array $skipEntries): void
    {
        $this->calls[] = 'swapHome:' . $incomingDir . ':' . $asideDir;
        if ($this->swapHomeThrows) {
            throw new \RuntimeException('home swap failed');
        }
    }

    public function removeEmptyHomeAside(string $asideDir): void
    {
        $this->calls[] = 'removeEmptyHomeAside:' . $asideDir;
    }

    public function rollbackHome(string $incomingDir, string $asideDir): void
    {
        $this->calls[] = 'rollbackHome:' . $incomingDir . ':' . $asideDir;
    }

    public function discardHomeAside(string $asideDir): void
    {
        $this->calls[] = 'discardHomeAside:' . $asideDir;
    }

    public function createDatabase(string $name): void
    {
        $this->calls[] = 'createDatabase:' . $name;
    }

    public function extractProjectArchive(string $archivePath, string $incomingDir): void
    {
        $this->calls[] = 'extractProjectArchive:' . $incomingDir;
    }

    public function swapProject(string $incomingDir, string $asideDir): void
    {
        $this->calls[] = 'swapProject:' . $incomingDir . ':' . $asideDir;
        if ($this->swapProjectThrows) {
            throw new \RuntimeException('swap failed');
        }
    }

    public function rollbackProject(string $asideDir): void
    {
        $this->calls[] = 'rollbackProject:' . $asideDir;
        if ($this->rollbackProjectThrows) {
            throw new \RuntimeException('rollback failed');
        }
    }

    public function discardProjectAside(string $asideDir): void
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

    private function stackDoubleFor(ProjectAggregate $project): AppStack
    {
        return new class ($project, $this) extends AppStack {
            public function __construct(ProjectAggregate $project, private BackupDouble $double)
            {
                parent::__construct($project);
            }

            public function stop(): void
            {
                $this->double->composeStop();
            }

            public function start(): void
            {
                $this->double->composeStart();
            }
        };
    }

    private function filesDoubleFor(ProjectAggregate $project, JobDeadline $deadline): ProjectFiles
    {
        return new class ($project, $deadline, $this) extends ProjectFiles {
            public function __construct(ProjectAggregate $project, JobDeadline $deadline, private BackupDouble $double)
            {
                parent::__construct($project, $deadline);
            }

            public function archive(string $dest): void
            {
                $this->double->tarProject($dest);
            }

            public function extract(string $archivePath, string $incomingDir): void
            {
                $this->double->extractProjectArchive($archivePath, $incomingDir);
            }

            public function swap(string $incomingDir, string $asideDir): void
            {
                $this->double->swapProject($incomingDir, $asideDir);
            }

            public function rollback(string $asideDir): void
            {
                $this->double->rollbackProject($asideDir);
            }

            public function discardAside(string $asideDir): void
            {
                $this->double->discardProjectAside($asideDir);
            }
        };
    }

    private function homeDoubleFor(ProjectAggregate $project, JobDeadline $deadline): HomeFiles
    {
        return new class ($project, $deadline, $this) extends HomeFiles {
            public function __construct(ProjectAggregate $project, JobDeadline $deadline, private BackupDouble $double)
            {
                parent::__construct($project, $deadline);
            }

            public function archive(string $dest): void
            {
                $this->double->tarHome($dest);
            }

            public function extract(string $archivePath, string $targetDir): void
            {
                $this->double->extractHomeArchive($archivePath, $targetDir);
            }

            public function merge(string $archivePath, string $incomingDir): void
            {
                $this->double->mergeIntoHome($archivePath, $incomingDir);
            }

            public function removeEntry(string $entry): void
            {
                $this->double->removeHomeEntry($entry);
            }

            public function removeEmptyAside(string $asideDir): void
            {
                $this->double->removeEmptyHomeAside($asideDir);
            }

            public function swap(string $incomingDir, string $asideDir, array $skipEntries): void
            {
                $this->double->swapHome($incomingDir, $asideDir, $skipEntries);
            }

            public function rollback(string $incomingDir, string $asideDir): void
            {
                $this->double->rollbackHome($incomingDir, $asideDir);
            }

            public function discardAside(string $asideDir): void
            {
                $this->double->discardHomeAside($asideDir);
            }
        };
    }

    private function volumesDoubleFor(ProjectAggregate $project, JobDeadline $deadline): ProjectVolumes
    {
        return new class ($project, $deadline, $this) extends ProjectVolumes {
            public function __construct(ProjectAggregate $project, JobDeadline $deadline, private BackupDouble $double)
            {
                parent::__construct($project, $deadline);
            }

            public function names(): array
            {
                return $this->double->namedVolumes();
            }

            public function archive(string $dockerName, string $dest): void
            {
                $this->double->tarVolume($dockerName, $dest);
            }

            public function ensure(string $dockerName): void
            {
                $this->double->ensureVolume($dockerName);
            }

            public function restore(string $dockerName, string $archivePath): void
            {
                $this->double->restoreVolume($dockerName, $archivePath);
            }

            public function rollback(string $dockerName): void
            {
                $this->double->rollbackVolume($dockerName);
            }
        };
    }

    private function databasesDoubleFor(ProjectAggregate $project, JobDeadline $deadline): ProjectDatabases
    {
        return new class ($project, $deadline, $this) extends ProjectDatabases {
            public function __construct(ProjectAggregate $project, JobDeadline $deadline, private BackupDouble $double)
            {
                parent::__construct($project, $deadline);
            }

            public function names(): array
            {
                return $this->double->databaseNames();
            }

            public function dump(string $name, string $dest): void
            {
                $this->double->dumpDatabase($name, $dest);
            }

            public function restore(string $name, string $sqlGzPath, ?int $timeout = null): void
            {
                $this->double->restoreDatabase($name, $sqlGzPath, $timeout);
            }

            public function restoreCopy(string $sourceName, string $name, string $sqlGzPath, ImportAccount $account): void
            {
                $this->double->restoreCopiedDatabase($sourceName, $name, $sqlGzPath, $account);
            }

            public function exists(string $name): bool
            {
                return true;
            }

            public function hasRow(string $name): bool
            {
                return true;
            }

            public function create(string $name): void
            {
                $this->double->createDatabase($name);
            }
        };
    }
}
