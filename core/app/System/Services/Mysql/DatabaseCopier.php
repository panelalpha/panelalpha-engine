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

    /**
     * The dump carries the source schema in its triggers, routines and events,
     * and the source DEFINER, so a copy into another database must rewrite both.
     *
     * The schema rewrite is restricted to lines that are not table data: a row
     * whose text happens to contain `src.` (an email, a host name, a note) must
     * survive untouched. Extended inserts wrap their rows onto lines starting
     * with `(`, so both the `INSERT INTO` line and its continuations are skipped.
     * The DEFINER rewrite follows on the same lines: mariadb-dump writes every
     * DEFINER clause on a CREATE line, never on a data line.
     */
    public static function rewrite(string $from, string $to, DefinerMap $definers): string
    {
        $pattern = preg_quote($from, '/');
        $replacement = str_replace(['\\', '&', '/'], ['\\\\', '\\&', '\\/'], $to);
        $expressions = [
            "s/`{$pattern}`\\./`{$replacement}`./g",
            "s/\\b{$pattern}\\./{$replacement}./g",
            ...$definers->sedExpressions(),
        ];

        return 'sed'.implode('', array_map(self::onSchemaLines(...), $expressions));
    }

    private static function onSchemaLines(string $expression): string
    {
        return " -e '/^INSERT INTO /!{/^(/!{$expression}}'";
    }

    public static function mapName(string $name, string $fromPrefix, string $toPrefix): string
    {
        if ($fromPrefix !== '' && str_starts_with($name, $fromPrefix)) {
            return $toPrefix.substr($name, strlen($fromPrefix));
        }

        return $toPrefix.$name;
    }

    public function copyDatabase(string $from, string $to, DefinerMap $definers): void
    {
        Mysql::assertDatabaseName($from);
        Mysql::assertDatabaseName($to);

        $this->mysql->databases()->createDatabase($to);
        $this->mysql->runSitesDbScript($this->streamCopy($from, $to, $definers), 'database copy failed');
    }

    /**
     * @param  callable(string): void  $log
     */
    public function copyProjectDatabases(User $source, User $dest, callable $log): DatabaseCopyResult
    {
        $fromPrefix = $source->getMysqlPrefix();
        $toPrefix = $dest->getMysqlPrefix();

        $definers = DefinerMap::forProjectCopy($source, $dest);
        $databases = $this->copyDatabases($source, $dest, $fromPrefix, $toPrefix, $definers, $log);
        $users = [];
        $passwords = [];
        $warnings = [];
        $this->copyUsers($source, $dest, $fromPrefix, $toPrefix, $log, $databases, $users, $passwords, $warnings);

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

    /**
     * $rollback is the completed dump of $to that dumpRollback() returned.
     */
    public function replaceDatabase(
        string $from,
        string $to,
        string $rollback,
        User $target,
        DefinerMap $definers,
    ): void {
        $grants = $this->targetGrants($target, $to);

        try {
            $this->mysql->databases()->deleteDatabase($to);
            $this->copyDatabase($from, $to, $definers);
        } catch (\Throwable $e) {
            $this->mysql->restoreFromGzip($to, $rollback);
            throw $e;
        }

        $this->reapplyGrants($grants, $to);
    }

    /**
     * One table of $to, dropped and recreated from $from. The caller dumps the
     * target's own copy first, so a failure can put it back.
     */
    public function copyTable(string $from, string $to, string $table, DefinerMap $definers): void
    {
        Mysql::assertDatabaseName($from);
        Mysql::assertDatabaseName($to);
        Mysql::assertTableName($table);

        $this->mysql->runSitesDbScript($this->streamTableCopy($from, $to, $table, $definers), 'table copy failed');
    }

    public function dropTable(string $database, string $table): void
    {
        Mysql::assertDatabaseName($database);
        Mysql::assertTableName($table);

        $this->mysql->runSitesDbScript(
            'mariadb -uroot '.escapeshellarg($database)
            .' -e '.escapeshellarg('DROP TABLE IF EXISTS '.Tables::quote($table)),
            'table drop failed'
        );
    }

    public static function tableRollbackPath(string $rollbackDir, string $database, string $table): string
    {
        return rtrim($rollbackDir, '/').'/'.$database.'.'.$table.'.sql.gz';
    }

    /**
     * Only the selected source tables, each dropped and recreated on its target
     * database. A target database the project does not own is created first.
     * The target's own copy of a replaced table is dumped before it is touched,
     * so a failure part way through restores what it already replaced, and a
     * failure in a later stage restores the whole selection. A restore that
     * fails too surfaces as a RollbackIncompleteException naming what it left.
     *
     * @param  list<array{database: string, name: string}>  $tables
     */
    public function replaceTables(User $source, User $target, array $tables, string $rollbackDir): TableReplacement
    {
        $grouped = self::groupByTarget($tables, $source->getMysqlPrefix(), $target->getMysqlPrefix());
        $targetTables = $this->mysql->tables();
        $rollbacks = [];
        $createdDatabases = [];
        $createdTables = [];

        try {
            $result = $this->replaceSelected(
                $grouped,
                $target,
                DefinerMap::between($source, $target),
                $rollbackDir,
                $targetTables,
                $rollbacks,
                $createdDatabases,
                $createdTables,
            );
        } catch (\Throwable $e) {
            $failures = $this->restoreTables(new TableReplacement(
                new DatabaseCopyResult,
                $rollbacks,
                $createdDatabases,
                $createdTables,
            ));
            throw $failures === [] ? $e : new RollbackIncompleteException($e, $failures);
        }

        return new TableReplacement($result, $rollbacks, $createdDatabases, $createdTables);
    }

    /**
     * @param  array<string, list<array{database: string, name: string}>>  $grouped
     * @param  list<array{database: string, table: string, dump: string}>  $rollbacks
     * @param  list<string>  $createdDatabases
     * @param  list<array{database: string, table: string}>  $createdTables
     */
    private function replaceSelected(
        array $grouped,
        User $target,
        DefinerMap $definers,
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
                MysqlDatabase::create(['user_id' => $target->id, 'database' => $dest]);
                $this->mysql->databases()->createDatabase($dest);
                $createdDatabases[] = $dest;
                $warnings[] = "Created database '{$dest}' without grants: the target does not own that database.";
            }

            foreach ($selections as $selection) {
                $this->replaceTable($selection, $dest, $definers, $rollbackDir, $targetTables, $rollbacks, $createdTables);
                $databases[$selection['database']] = $dest;
            }
        }

        return new DatabaseCopyResult($databases, warnings: $warnings);
    }

    /**
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
                if ($this->mysql->databases()->databaseExists($database)) {
                    $this->mysql->databases()->deleteDatabase($database);
                }
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
        DefinerMap $definers,
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

        $this->copyTable($selection['database'], $dest, $table, $definers);
    }

    /**
     * @param  list<array{database: string, name: string}>  $tables
     * @return array<string, list<array{database: string, name: string}>>
     */
    private static function groupByTarget(array $tables, string $sourcePrefix, string $targetPrefix): array
    {
        $grouped = [];
        foreach ($tables as $selection) {
            $grouped[self::mapName($selection['database'], $sourcePrefix, $targetPrefix)][] = $selection;
        }

        return $grouped;
    }

    public static function rollbackPath(string $rollbackDir, string $database): string
    {
        return rtrim($rollbackDir, '/').'/'.$database.'.sql.gz';
    }

    /**
     * The row is written before the data: a dump that dies mid-stream must
     * still leave a database the destination's teardown can drop.
     *
     * @param  callable(string): void  $log
     * @return array<string, string> source database => destination database
     */
    private function copyDatabases(
        User $source,
        User $dest,
        string $fromPrefix,
        string $toPrefix,
        DefinerMap $definers,
        callable $log,
    ): array {
        $databases = [];
        foreach ($source->mysqlDatabases as $row) {
            $from = $row->database;
            $to = self::mapName($from, $fromPrefix, $toPrefix);
            MysqlDatabase::create(['user_id' => $dest->id, 'database' => $to]);
            $log("Copying database {$from} to {$to}");
            $this->copyDatabase($from, $to, $definers);
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

    private function streamCopy(string $from, string $to, DefinerMap $definers): string
    {
        $dump = 'mariadb-dump --single-transaction --routines --triggers --events -uroot '.escapeshellarg($from);
        $import = 'mariadb -uroot '.escapeshellarg($to);

        return 'set -o pipefail; '.$dump.' | '.self::rewrite($from, $to, $definers).' | '.$import;
    }

    private function streamTableCopy(string $from, string $to, string $table, DefinerMap $definers): string
    {
        $dump = 'mariadb-dump --single-transaction -uroot '
            .escapeshellarg($from).' '.escapeshellarg($table);
        $import = 'mariadb -uroot '.escapeshellarg($to);

        return 'set -o pipefail; '.$dump.' | '.self::rewrite($from, $to, $definers).' | '.$import;
    }
}
