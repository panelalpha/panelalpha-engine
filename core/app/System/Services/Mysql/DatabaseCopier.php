<?php

namespace App\System\Services\Mysql;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System\Services\Mysql;

/**
 * The MySQL half of a project copy: every database the source owns, under the
 * destination's prefix, with fresh credentials and the grants that went with it.
 */
class DatabaseCopier
{
    /** Plain sed; this tree has no WordPress Database class to borrow the strip from. */
    private const DEFINER_STRIP = "sed -e 's/DEFINER=`[^`]*`@`[^`]*`//g'";

    public function __construct(
        private readonly Mysql $mysql,
    ) {
    }

    public static function mapName(string $name, string $fromPrefix, string $toPrefix): string
    {
        if ($fromPrefix !== '' && str_starts_with($name, $fromPrefix)) {
            return $toPrefix . substr($name, strlen($fromPrefix));
        }

        return $toPrefix . $name;
    }

    public function copyDatabase(string $from, string $to): void
    {
        Mysql::assertDatabaseName($from);
        Mysql::assertDatabaseName($to);

        $this->mysql->databases()->createDatabase($to);
        $this->mysql->runSitesDbScript($this->streamCopy($from, $to), 'database copy failed');
    }

    /**
     * @param callable(string): void $log
     */
    public function copyProjectDatabases(User $source, User $dest, callable $log): DatabaseCopyResult
    {
        $fromPrefix = $source->getMysqlPrefix();
        $toPrefix = $dest->getMysqlPrefix();

        $databases = $this->copyDatabases($source, $dest, $fromPrefix, $toPrefix, $log);
        $users = [];
        $passwords = [];
        $warnings = [];
        $this->copyUsers($source, $dest, $fromPrefix, $toPrefix, $log, $databases, $users, $passwords, $warnings);

        return new DatabaseCopyResult($databases, $users, $passwords, $warnings);
    }

    public function replaceDatabase(string $from, string $to, string $rollbackDir, User $target): void
    {
        $rollback = self::rollbackPath($rollbackDir, $to);
        $this->mysql->dumpToGzip($to, $rollback);
        $grants = $this->targetGrants($target, $to);

        try {
            $this->mysql->databases()->deleteDatabase($to);
            $this->copyDatabase($from, $to);
        } catch (\Throwable $e) {
            $this->mysql->restoreFromGzip($to, $rollback);
            throw $e;
        }

        $this->reapplyGrants($grants, $to);
    }

    public static function rollbackPath(string $rollbackDir, string $database): string
    {
        return rtrim($rollbackDir, '/') . '/' . $database . '.sql.gz';
    }

    /**
     * The row is written before the data: a dump that dies mid-stream must
     * still leave a database the destination's teardown can drop.
     *
     * @param callable(string): void $log
     * @return array<string, string> source database => destination database
     */
    private function copyDatabases(
        User $source,
        User $dest,
        string $fromPrefix,
        string $toPrefix,
        callable $log,
    ): array {
        $databases = [];
        foreach ($source->mysqlDatabases as $row) {
            $from = $row->database;
            $to = self::mapName($from, $fromPrefix, $toPrefix);
            MysqlDatabase::create(['user_id' => $dest->id, 'database' => $to]);
            $log("Copying database {$from} to {$to}");
            $this->copyDatabase($from, $to);
            $databases[$from] = $to;
        }

        return $databases;
    }

    /**
     * @param callable(string): void $log
     * @param array<string, string> $databases
     * @param array<string, string> $users
     * @param array<string, string> $passwords
     * @param list<string>          $warnings
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
     * @param array<string, string> $databases
     * @param list<string>          $warnings
     */
    private function copyGrants(string $fromUser, string $toUser, array $databases, array &$warnings): void
    {
        foreach ($this->mysql->privileges()->showPrivileges($fromUser) as $database => $privileges) {
            if (!isset($databases[$database])) {
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
     * @param array<string, string> $grants
     */
    private function reapplyGrants(array $grants, string $database): void
    {
        foreach ($grants as $user => $privileges) {
            $this->mysql->privileges()->updatePrivileges($user, $database, $privileges);
        }
    }

    private function streamCopy(string $from, string $to): string
    {
        $dump = 'mariadb-dump --single-transaction --routines --triggers --events -uroot ' . escapeshellarg($from);
        $import = 'mariadb -uroot ' . escapeshellarg($to);

        return 'set -o pipefail; ' . $dump . ' | ' . self::DEFINER_STRIP . ' | ' . $import;
    }
}
