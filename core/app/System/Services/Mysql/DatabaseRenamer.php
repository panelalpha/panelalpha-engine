<?php

namespace App\System\Services\Mysql;

use App\Models\MysqlDatabase;
use App\Models\User;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Log;

/**
 * Renames one MySQL database in place: its data, the grants that pointed at it
 * and the row that records it. The old database is dropped only after all of
 * that succeeded; a failure before the drop moves any moved tables back,
 * removes the new database and puts the grants back where they were.
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
        $moved = [];

        try {
            $moved = $this->copy($oldName, $newName, DefinerMap::between($user, $user));
            $this->moveGrants($grants, $oldName, $newName);
        } catch (\Throwable $e) {
            throw $this->undo($grants, $oldName, $newName, $moved, $e);
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
     *
     * @return list<string> the tables moved out of $from, none for a copy
     */
    private function copy(string $from, string $to, DefinerMap $definers): array
    {
        if ($this->mysql->databases()->hasObjectsBeyondTables($from)) {
            $this->copier->copyDatabase($from, $to, $definers);

            return [];
        }

        $this->mysql->databases()->createDatabase($to);
        $tables = $this->mysql->databases()->tables($from);
        if ($tables !== []) {
            $this->mysql->databases()->renameTables($from, $to, $tables);
        }

        return $tables;
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
     * The new database is dropped only once it holds nothing of the old one: a
     * copy, or tables already moved back. Tables that cannot be moved back stay
     * in it, and the failure says where they are.
     *
     * @param array<string, string> $grants
     * @param list<string>          $moved
     */
    private function undo(array $grants, string $from, string $to, array $moved, \Throwable $cause): \Throwable
    {
        $movedBack = $this->moveBack($to, $from, $moved);
        if ($movedBack) {
            $this->dropIfPresent($to);
        }

        foreach ($grants as $user => $privileges) {
            $this->mysql->privileges()->revokeIfExists($user, $to);
            $this->mysql->privileges()->updatePrivileges($user, $from, $privileges);
        }

        if ($movedBack) {
            return $cause;
        }

        return new \RuntimeException(
            $cause->getMessage() . " The tables could not be moved back to '{$from}' and are kept in '{$to}'.",
            0,
            $cause,
        );
    }

    /**
     * @param list<string> $tables
     */
    private function moveBack(string $from, string $to, array $tables): bool
    {
        if ($tables === []) {
            return true;
        }

        try {
            $this->mysql->databases()->renameTables($from, $to, $tables);
        } catch (\Throwable $e) {
            Log::warning("Database rename could not move the tables of '{$from}' back to '{$to}': " . $e->getMessage());

            return false;
        }

        return true;
    }

    private function dropIfPresent(string $name): void
    {
        try {
            $this->mysql->databases()->deleteDatabase($name);
        } catch (\Throwable $ignored) {
        }
    }
}
