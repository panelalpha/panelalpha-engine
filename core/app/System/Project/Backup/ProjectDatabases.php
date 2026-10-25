<?php

namespace App\System\Project\Backup;

use App\Models\MysqlDatabase;
use App\Support\JobDeadline;
use App\System\Project as UserProject;
use App\System\Services\Mysql\DefinerMap;
use Illuminate\Support\Facades\Log;

/** The account's databases on the shared MariaDB, dumped and restored as gzipped SQL. */
class ProjectDatabases
{
    public function __construct(
        private UserProject $project,
        private JobDeadline $deadline,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $model = $this->project->model();
        $model->loadMissing('mysqlDatabases');

        /** @var list<string> $names */
        $names = $model->mysqlDatabases
            ->pluck('database')
            ->values()
            ->all();

        return $names;
    }

    public function dump(string $name, string $dest): void
    {
        $this->project->system()->mysql()->withTimeout($this->deadline->stepSeconds())->dumpToGzip($name, $dest);
    }

    public function restore(string $name, string $sqlGzPath, ?int $timeout = null): void
    {
        $this->project->system()->mysql()->withTimeout($timeout ?? $this->deadline->stepSeconds())->restoreFromGzip($name, $sqlGzPath);
    }

    public function restoreCopy(string $sourceName, string $name, string $sqlGzPath, DefinerMap $definers): void
    {
        $this->project->system()->mysql()->withTimeout($this->deadline->stepSeconds())->restoreCopyFromGzip($sourceName, $name, $sqlGzPath, $definers);
    }

    public function size(string $name): int
    {
        return $this->project->system()->mysql()->databases()->getDatabaseSize($name);
    }

    public function exists(string $name): bool
    {
        return $this->project->system()->mysql()->databases()->databaseExists($name);
    }

    public function hasRow(string $name): bool
    {
        $model = $this->project->model();
        $model->loadMissing('mysqlDatabases');

        return $model->mysqlDatabases->contains('database', $name);
    }

    public function create(string $name): void
    {
        $this->project->system()->mysql()->databases()->createDatabase($name);
    }

    /** A database dropped since the dump was taken is recreated, without grants, so its data can come back. */
    public function ensureRow(string $name): void
    {
        if ($this->hasRow($name)) {
            return;
        }

        $model = $this->project->model();
        Log::warning('Restoring a database whose row no longer exists', [
            'username' => $this->project->username(),
            'database' => $name,
        ]);

        if (! $this->exists($name)) {
            $this->create($name);
        }
        MysqlDatabase::create(['user_id' => $model->id, 'database' => $name]);
    }
}
