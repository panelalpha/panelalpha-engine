<?php

namespace App\System\Services\Mysql;

use App\Models\MysqlDatabase;
use App\Models\User;
use App\System\Services\Mysql;

/**
 * Renames one MySQL database in place: its data, the grants that pointed at it
 * and the row that records it. The old database is dropped only after all of
 * that succeeded; a failure before the drop removes the half-made copy and puts
 * the grants back where they were.
 */
class DatabaseRenamer
{
    public function __construct(
        private readonly Mysql $mysql,
        private readonly DatabaseCopier $copier,
    ) {
    }

    public function rename(User $user, MysqlDatabase $row, string $newName): void
    {
        $oldName = $row->database;
        $this->refuseExisting($newName);

        $grants = $this->grantsOn($user, $oldName);

        try {
            $this->copy($oldName, $newName);
            $this->moveGrants($grants, $oldName, $newName);
        } catch (\Throwable $e) {
            $this->undo($grants, $oldName, $newName);
            throw $e;
        }

        $row->update(['database' => $newName]);
        $this->mysql->databases()->deleteDatabase($oldName);
    }

    private function refuseExisting(string $name): void
    {
        if ($this->mysql->databases()->databaseExists($name)) {
            throw new \RuntimeException("Database '{$name}' already exists");
        }
    }

    /**
     * A database of only base tables moves by metadata alone, in one atomic
     * statement. Anything else -- a view, trigger, routine or event -- cannot,
     * and is streamed over by a dump and an import instead.
     */
    private function copy(string $from, string $to): void
    {
        if ($this->mysql->databases()->hasObjectsBeyondTables($from)) {
            $this->copier->copyDatabase($from, $to);

            return;
        }

        $this->mysql->databases()->createDatabase($to);
        $tables = $this->mysql->databases()->tables($from);
        if ($tables !== []) {
            $this->mysql->databases()->renameTables($from, $to, $tables);
        }
    }

    /**
     * @return array<string, string> MySQL user => privileges held on the database
     */
    private function grantsOn(User $user, string $database): array
    {
        $grants = [];
        foreach ($user->mysqlUsers as $row) {
            $privileges = $this->mysql->privileges()->showPrivileges($row->user)[$database] ?? null;
            if ($privileges !== null) {
                $grants[$row->user] = $privileges;
            }
        }

        return $grants;
    }

    /**
     * @param array<string, string> $grants
     */
    private function moveGrants(array $grants, string $from, string $to): void
    {
        foreach ($grants as $user => $privileges) {
            $this->mysql->privileges()->updatePrivileges($user, $to, $privileges);
            $this->mysql->privileges()->revokeIfExists($user, $from);
        }
    }

    /**
     * @param array<string, string> $grants
     */
    private function undo(array $grants, string $from, string $to): void
    {
        $this->dropIfPresent($to);
        foreach ($grants as $user => $privileges) {
            $this->mysql->privileges()->revokeIfExists($user, $to);
            $this->mysql->privileges()->updatePrivileges($user, $from, $privileges);
        }
    }

    private function dropIfPresent(string $name): void
    {
        try {
            $this->mysql->databases()->deleteDatabase($name);
        } catch (\Throwable $ignored) {
        }
    }
}
