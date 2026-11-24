<?php

namespace App\System\Services\Mysql;

use App\Exceptions\RollbackIncompleteException;
use App\Lib\Deploy\Credentials\AppCredentials;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Log;

/**
 * The MySQL half of a project copy: every database the source owns, under the
 * destination's prefix, with fresh credentials and the grants that went with it.
 */
class DatabaseCopier
{
    public function __construct(
        private readonly Mysql $mysql,
    ) {}

    public static function mapName(string $name, string $fromPrefix, string $toPrefix): string
    {
        if ($fromPrefix !== '' && str_starts_with($name, $fromPrefix)) {
            return $toPrefix.substr($name, strlen($fromPrefix));
        }

        return $toPrefix.$name;
    }

    public function copyDatabase(string $from, string $to, ImportAccount $account): void
    {
        Mysql::assertDatabaseName($to);

        $this->mysql->databases()->createDatabase($to);
        $this->copyInto($from, $to, $account);
    }

    /** $from loaded into the existing $to as $account, which must already hold its grants on $to. */
    public function copyInto(string $from, string $to, ImportAccount $account): void
    {
        Mysql::assertDatabaseName($from);
        Mysql::assertDatabaseName($to);

        $this->mysql->copyInSitesDb($from, $to, null, $account, 'database copy failed');
    }

    /**
     * The users and their grants come first, so each database is loaded as the one copied user granted on it.
     *
     * @param  callable(string): void  $log
     */
    public function copyProjectDatabases(User $source, User $dest, callable $log): DatabaseCopyResult
    {
        $fromPrefix = $source->getMysqlPrefix();
        $toPrefix = $dest->getMysqlPrefix();

        $databases = $this->createDatabases($source, $dest, $fromPrefix, $toPrefix);
        $users = [];
        $passwords = [];
        $warnings = [];
        $this->copyUsers($source, $dest, $fromPrefix, $toPrefix, $log, $databases, $users, $passwords, $warnings);
        foreach ($databases as $from => $to) {
            $log("Copying database {$from} to {$to}");
            $this->copyInto($from, $to, $this->mysql->importAccount($dest, $to));
        }

        return new DatabaseCopyResult($databases, $users, $passwords, $warnings);
    }

    /**
     * The target's own copy, dumped before a replace touches it. Returns only
     * once the dump is complete; a failed dump leaves no file behind.
     */
    public function dumpRollback(string $database, string $rollbackDir): string
    {
        $rollback = self::rollbackPath($rollbackDir, $database);
        $this->mysql->dumpToGzip($database, $rollback);

        return $rollback;
    }

    /** $rollback is dumpRollback()'s dump of $to; $account keeps its grants across the drop, as MariaDB keeps them. */
    public function replaceDatabase(
        string $from,
        string $to,
        string $rollback,
        User $target,
        ImportAccount $account,
    ): void {
        $grants = $this->targetGrants($target, $to);

        try {
            $this->mysql->databases()->deleteDatabase($to);
            $this->copyDatabase($from, $to, $account);
        } catch (\Throwable $e) {
            $this->mysql->restoreFromGzip($to, $rollback);
            throw $e;
        }

        $this->reapplyGrants($grants, $to);
    }

    /**
     * A database a push created gets, for each source user, what that user had on the source database, granted to the
     * target user it maps to; without it they could not use the data, nor could a copy be imported as one of them.
     *
     * @return list<string> a warning when no target user was granted anything
     */
    public function grantCreatedDatabase(string $from, string $to, AccountMap $userMap): array
    {
        $granted = false;
        foreach ($userMap->accounts() as $sourceUser => $targetUser) {
            $privileges = $this->mysql->privileges()->showPrivileges($sourceUser)[$from] ?? null;
            if ($privileges !== null) {
                $this->mysql->privileges()->updatePrivileges($targetUser, $to, $privileges);
                $granted = true;
            }
        }

        return $granted ? [] : ["Created database '{$to}' with no grants: no user of the target maps to a source user with grants on '{$from}'."];
    }

    /**
     * Drops a database a push created and takes back what grantCreatedDatabase() gave on it: MariaDB keeps a grant on
     * a dropped name, and a database later created under that name would inherit it.
     */
    public function dropCreatedDatabase(string $database, AccountMap $userMap): void
    {
        if ($this->mysql->databases()->databaseExists($database)) {
            $this->mysql->databases()->deleteDatabase($database);
        }
        foreach ($userMap->accounts() as $targetUser) {
            $this->mysql->privileges()->revokeIfExists($targetUser, $database);
        }
    }

    /**
     * One table of $to, dropped and recreated from $from. The caller dumps the
     * target's own copy first, so a failure can put it back.
     */
    public function copyTable(string $from, string $to, string $table, ImportAccount $account): void
    {
        Mysql::assertDatabaseName($from);
        Mysql::assertDatabaseName($to);
        Mysql::assertTableName($table);

        $this->mysql->copyInSitesDb($from, $to, $table, $account, 'table copy failed');
    }

    public function dropTable(string $database, string $table): void
    {
        Mysql::assertDatabaseName($database);
        Mysql::assertTableName($table);

        $this->mysql->dropTableInSitesDb($database, $table);
    }

    public static function tableRollbackPath(string $rollbackDir, string $database, string $table): string
    {
        return rtrim($rollbackDir, '/').'/'.$database.'.'.$table.'.sql.gz';
    }

    /**
     * Only the selected source tables, each dropped and recreated on its target database; one the project does not own
     * is created first, with the grants the source users had on the source database.
     *
     * @param  list<array{database: string, name: string}>  $tables
     *
     * @throws RollbackIncompleteException when undoing a failure fails too, naming what it left
     */
    public function replaceTables(User $source, User $target, array $tables, string $rollbackDir): TableReplacement
    {
        $grouped = self::groupByTarget($tables, $source->getMysqlPrefix(), $target->getMysqlPrefix());
        $owned = $target->mysqlDatabases()->pluck('database')->all();
        $accounts = [];
        foreach (array_keys($grouped) as $dest) {
            if (in_array($dest, $owned, true)) {
                $accounts[$dest] = $this->mysql->importAccount($target, $dest);
            }
        }
        $targetTables = $this->mysql->tables();
        $userMap = AccountMap::between($source, $target);
        $rollbacks = [];
        $createdDatabases = [];
        $createdTables = [];

        try {
            $result = $this->replaceSelected(
                $grouped,
                $accounts,
                $target,
                $userMap,
                $rollbackDir,
                $targetTables,
                $rollbacks,
                $createdDatabases,
                $createdTables,
            );
        } catch (\Throwable $e) {
            throw $this->rolledBack($e, $userMap, $rollbacks, $createdDatabases, $createdTables);
        }

        return new TableReplacement($result, $userMap, $rollbacks, $createdDatabases, $createdTables);
    }

    /**
     * Each replaced table's own copy was dumped before it was touched, so a failure part way through restores what it
     * already replaced.
     *
     * @param  list<array{database: string, table: string, dump: string}>  $rollbacks
     * @param  list<string>  $createdDatabases
     * @param  list<array{database: string, table: string}>  $createdTables
     */
    private function rolledBack(
        \Throwable $e,
        AccountMap $userMap,
        array $rollbacks,
        array $createdDatabases,
        array $createdTables,
    ): \Throwable {
        $failures = $this->restoreTables(new TableReplacement(
            new DatabaseCopyResult,
            $userMap,
            $rollbacks,
            $createdDatabases,
            $createdTables,
        ));

        return $failures === [] ? $e : new RollbackIncompleteException($e, $failures);
    }

    /**
     * @param  array<string, list<array{database: string, name: string}>>  $grouped
     * @param  array<string, ImportAccount>  $accounts  owned target database => the account it is loaded as
     * @param  list<array{database: string, table: string, dump: string}>  $rollbacks
     * @param  list<string>  $createdDatabases
     * @param  list<array{database: string, table: string}>  $createdTables
     */
    private function replaceSelected(
        array $grouped,
        array $accounts,
        User $target,
        AccountMap $userMap,
        string $rollbackDir,
        Tables $targetTables,
        array &$rollbacks,
        array &$createdDatabases,
        array &$createdTables,
    ): DatabaseCopyResult {
        $owned = $target->mysqlDatabases()->pluck('database')->all();
        $databases = [];
        $warnings = [];

        foreach ($grouped as $dest => $selections) {
            if (! in_array($dest, $owned, true)) {
                $this->createTargetDatabase($target, $dest);
                $createdDatabases[] = $dest;
                array_push($warnings, ...$this->grantCreatedDatabase($selections[0]['database'], $dest, $userMap));
                $accounts[$dest] = $this->mysql->importAccount($target, $dest);
            }

            foreach ($selections as $selection) {
                $this->replaceTable($selection, $dest, $accounts[$dest], $rollbackDir, $targetTables, $rollbacks, $createdTables);
                $databases[$selection['database']] = $dest;
            }
        }

        return new DatabaseCopyResult($databases, warnings: $warnings);
    }

    /**
     * The row goes when the database cannot be created: that database is not
     * the push's to drop, but the target must not own it either.
     */
    private function createTargetDatabase(User $target, string $database): void
    {
        $row = MysqlDatabase::create(['user_id' => $target->id, 'database' => $database]);
        try {
            $this->mysql->databases()->createDatabase($database);
        } catch (\Throwable $e) {
            $row->delete();
            throw $e;
        }
    }

    /**
     * A failure in a later stage of the push restores the whole selection through here.
     *
     * @return list<string> every step that could not be undone
     */
    public function restoreTables(TableReplacement $replacement): array
    {
        $failures = [];
        foreach ($replacement->rollbacks as $entry) {
            try {
                $this->mysql->restoreFromGzip($entry['database'], $entry['dump']);
            } catch (\Throwable $e) {
                $failures[] = "restoring table '{$entry['database']}.{$entry['table']}'";
                Log::warning(
                    "Push rollback failed for table '{$entry['database']}.{$entry['table']}': ".$e->getMessage()
                );
            }
        }

        return [...$failures, ...$this->dropCreated($replacement)];
    }

    /**
     * @return list<string> every drop that failed
     */
    private function dropCreated(TableReplacement $replacement): array
    {
        $failures = [];
        foreach ($replacement->createdTables as $entry) {
            try {
                $this->dropTable($entry['database'], $entry['table']);
            } catch (\Throwable $e) {
                $failures[] = "dropping created table '{$entry['database']}.{$entry['table']}'";
                Log::warning(
                    "Push cleanup failed for table '{$entry['database']}.{$entry['table']}': ".$e->getMessage()
                );
            }
        }

        foreach ($replacement->createdDatabases as $database) {
            try {
                $this->dropCreatedDatabase($database, $replacement->userMap);
                MysqlDatabase::query()->where('database', $database)->delete();
            } catch (\Throwable $e) {
                $failures[] = "dropping created database '{$database}'";
                Log::warning("Push cleanup failed for database '{$database}': ".$e->getMessage());
            }
        }

        return $failures;
    }

    /**
     * @param  array{database: string, name: string}  $selection
     * @param  list<array{database: string, table: string, dump: string}>  $rollbacks
     * @param  list<array{database: string, table: string}>  $createdTables
     */
    private function replaceTable(
        array $selection,
        string $dest,
        ImportAccount $account,
        string $rollbackDir,
        Tables $targetTables,
        array &$rollbacks,
        array &$createdTables,
    ): void {
        $table = $selection['name'];

        if ($targetTables->exists($dest, $table)) {
            $dump = self::tableRollbackPath($rollbackDir, $dest, $table);
            $this->mysql->dumpToGzip($dest, $dump, $table);
            $rollbacks[] = ['database' => $dest, 'table' => $table, 'dump' => $dump];
        } else {
            $createdTables[] = ['database' => $dest, 'table' => $table];
        }

        $this->copyTable($selection['database'], $dest, $table, $account);
    }

    /**
     * A target table selected twice is replaced once: a second rollback dump
     * would hold the source's copy over the target's original.
     *
     * @param  list<array{database: string, name: string}>  $tables
     * @return array<string, list<array{database: string, name: string}>>
     */
    private static function groupByTarget(array $tables, string $sourcePrefix, string $targetPrefix): array
    {
        $grouped = [];
        foreach ($tables as $selection) {
            $grouped[self::mapName($selection['database'], $sourcePrefix, $targetPrefix)][$selection['name']] = $selection;
        }

        return array_map(array_values(...), $grouped);
    }

    public static function rollbackPath(string $rollbackDir, string $database): string
    {
        return rtrim($rollbackDir, '/').'/'.$database.'.sql.gz';
    }

    /**
     * The row is written before the data: a dump that dies mid-stream must
     * still leave a database the destination's teardown can drop.
     *
     * @return array<string, string> source database => destination database
     */
    private function createDatabases(User $source, User $dest, string $fromPrefix, string $toPrefix): array
    {
        $databases = [];
        foreach ($source->mysqlDatabases as $row) {
            $from = $row->database;
            $to = self::mapName($from, $fromPrefix, $toPrefix);
            Mysql::assertDatabaseName($to);
            MysqlDatabase::create(['user_id' => $dest->id, 'database' => $to]);
            $this->mysql->databases()->createDatabase($to);
            $databases[$from] = $to;
        }

        return $databases;
    }

    /**
     * @param  callable(string): void  $log
     * @param  array<string, string>  $databases
     * @param  array<string, string>  $users
     * @param  array<string, string>  $passwords
     * @param  list<string>  $warnings
     */
    private function copyUsers(
        User $source,
        User $dest,
        string $fromPrefix,
        string $toPrefix,
        callable $log,
        array $databases,
        array &$users,
        array &$passwords,
        array &$warnings,
    ): void {
        foreach ($source->mysqlUsers as $row) {
            $from = $row->user;
            $to = self::mapName($from, $fromPrefix, $toPrefix);
            $password = AppCredentials::generatePassword();

            MysqlUser::create(['user_id' => $dest->id, 'user' => $to]);
            $this->mysql->users()->createUser($to, $password);
            $log("Copying MySQL user {$from} to {$to}");

            $users[$from] = $to;
            $passwords[$to] = $password;
            $this->copyGrants($from, $to, $databases, $warnings);
        }
    }

    /**
     * @param  array<string, string>  $databases
     * @param  list<string>  $warnings
     */
    private function copyGrants(string $fromUser, string $toUser, array $databases, array &$warnings): void
    {
        foreach ($this->mysql->privileges()->showPrivileges($fromUser) as $database => $privileges) {
            if (! isset($databases[$database])) {
                $warnings[] = "Skipped a grant on '{$database}': the source project does not own that database.";

                continue;
            }

            $this->mysql->privileges()->updatePrivileges($toUser, $databases[$database], $privileges);
        }
    }

    /**
     * @return array<string, string> target user => privileges
     */
    private function targetGrants(User $target, string $database): array
    {
        $grants = [];
        foreach ($target->mysqlUsers as $row) {
            $privileges = $this->mysql->privileges()->showPrivileges($row->user)[$database] ?? null;
            if ($privileges !== null) {
                $grants[$row->user] = $privileges;
            }
        }

        return $grants;
    }

    /**
     * @param  array<string, string>  $grants
     */
    private function reapplyGrants(array $grants, string $database): void
    {
        foreach ($grants as $user => $privileges) {
            $this->mysql->privileges()->updatePrivileges($user, $database, $privileges);
        }
    }
}
