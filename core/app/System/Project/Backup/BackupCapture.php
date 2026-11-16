<?php

namespace App\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Support\JobDeadline;
use App\System\Project as UserProject;

/** What a backup captures into its staging directory, and the writing of it. */
final class BackupCapture
{
    public function __construct(
        private UserProject $project,
        private BackupRecord $record,
        private JobDeadline $deadline,
        private ProjectContent $content,
    ) {}

    /**
     * @return list<array{path: string, file: string, type: 'files'|'volume'|'database', name: string, scope?: string, docker_name?: string}>
     */
    public function manifest(string $stagingDir): array
    {
        $files = [];

        if ($this->record->includeFiles()) {
            $files[] = $this->filesEntry($stagingDir);
        }

        if (! $this->record->includeDatabases()) {
            return $files;
        }

        if ($this->project->runsInDind()) {
            foreach ($this->content->volumes->names() as $volume) {
                $files[] = $this->volumeEntry($stagingDir, $volume);
            }
        }

        foreach ($this->content->databases->names() as $database) {
            $files[] = [
                'path' => $stagingDir.'database-'.$database.'.sql.gz',
                'file' => 'database-'.$database.'.sql.gz',
                'type' => 'database',
                'name' => $database,
            ];
        }

        return $files;
    }

    public function write(string $stagingDir): void
    {
        foreach ($this->manifest($stagingDir) as $file) {
            $this->deadline->assertTimeLeft();
            match ($file['type']) {
                'files' => ($file['scope'] ?? null) === 'home'
                    ? $this->content->home->archive($file['path'])
                    : $this->content->files->archive($file['path']),
                'volume' => $this->content->volumes->archive($file['docker_name'], $file['path']),
                'database' => $this->content->databases->dump($file['name'], $file['path']),
            };
        }
    }

    public function requiredBytes(): int
    {
        $estimate = 1024 * 1024 * 1024;
        if (! $this->record->includeDatabases()) {
            return $estimate;
        }

        $volumes = $this->project->runsInDind() ? count($this->content->volumes->names()) : 0;

        return $estimate + ($volumes + count($this->content->databases->names())) * (100 * 1024 * 1024);
    }

    /**
     * @return array{path: string, file: string, type: string, name: string, scope?: string}
     */
    private function filesEntry(string $stagingDir): array
    {
        return $this->project->runsInDind()
            ? [
                'path' => $stagingDir.'files.tar.gz',
                'file' => 'files.tar.gz',
                'type' => 'files',
                'name' => 'files',
            ]
            : [
                'path' => $stagingDir.'home.tar.gz',
                'file' => 'home.tar.gz',
                'type' => 'files',
                'name' => 'home',
                'scope' => 'home',
            ];
    }

    /**
     * @param  array{name: string, docker_name: string}  $volume
     * @return array{path: string, file: string, type: string, name: string, docker_name: string}
     */
    private function volumeEntry(string $stagingDir, array $volume): array
    {
        return [
            'path' => $stagingDir.'volume-'.$volume['name'].'.tar.gz',
            'file' => 'volume-'.$volume['name'].'.tar.gz',
            'type' => 'volume',
            'name' => $volume['name'],
            'docker_name' => $volume['docker_name'],
        ];
    }
}
