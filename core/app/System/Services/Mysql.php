<?php

namespace App\System\Services;

use App\Models\User;
use App\Support\JobBudget;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Privileged\RootEngineComposeLines;
use App\System\Project\Dind\AppDatabase;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Tables;
use App\System\Services\Mysql\Users;
use PDO;
use RuntimeException;

class Mysql
{
    /**
     * Below the job's own budget, so a long dump/restore fails through this
     * timeout and cleans up before the job's alarm SIGKILLs the worker.
     */
    private const DB_COMMAND_TIMEOUT = JobBudget::COMMAND_SECONDS;

    private ?PDO $pdo = null;

    /** @var string */
    protected $host = '%';

    public function __construct(
        private readonly ?EngineSystem $system = null,
        private ?int $timeout = null,
    ) {}

    /** Run this connection on one job step's budget, so a long dump cannot outlast its job. */
    public function withTimeout(int $timeout): self
    {
        $clone = clone $this;
        $clone->timeout = $timeout;

        return $clone;
    }

    public function databases(): Databases
    {
        return new Databases($this->pdo());
    }

    public function users(): Users
    {
        return new Users($this->pdo(), $this->host);
    }

    public function privileges(): Privileges
    {
        return new Privileges($this->pdo(), $this->host);
    }

    public function tables(): Tables
    {
        return new Tables($this->pdo());
    }

    /**
     * A dump that fails is removed, so no caller can mistake a partial file for
     * a rollback or a backup.
     */
    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
    {
        self::assertDatabaseName($name);
        if ($table !== null) {
            self::assertTableName($table);
        }

        try {
            $this->runInCore($this->sitesDb()->sitesDbDump($name, $table).' | gzip -c > '.escapeshellarg($dest), 'mysqldump failed');
            $this->assertDumpCompleted($table ?? $name, $dest);
        } catch (\Throwable $e) {
            $this->discardDump($dest);
            throw $e;
        }
    }

    private function discardDump(string $dest): void
    {
        try {
            $this->runInCore('rm -f '.escapeshellarg($dest), 'removing a failed dump failed');
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * A dump cut short still gzips cleanly; only a finished one ends with `-- Dump completed`.
     */
    private function assertDumpCompleted(string $name, string $dest): void
    {
        $last = trim($this->runInCore('gzip -cd '.escapeshellarg($dest).' | tail -n 1', 'mysqldump failed'));
        if (! str_starts_with($last, '-- Dump completed')) {
            throw new RuntimeException("mysqldump failed: the dump of {$name} is incomplete");
        }
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        self::assertDatabaseName($name);
        $this->runInCore('gunzip -c '.escapeshellarg($sqlGzPath).' | '.$this->sitesDb()->sitesDbRestore($name), 'database restore failed');
    }

    /** A dump of $from loaded into $to as copyInSitesDb() loads a stream. */
    public function restoreCopyFromGzip(string $from, string $to, string $sqlGzPath, ImportAccount $account): void
    {
        self::assertDatabaseName($from);
        self::assertDatabaseName($to);
        $this->runInCore(
            'gunzip -c '.escapeshellarg($sqlGzPath).' | '.$this->sitesDb()->sitesDbRestoreCopy($from, $to, $account->user),
            'database restore failed',
        );
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = self::configString('env.USERS_DB_HOST', 'localhost');
        $user = self::configString('env.USERS_DB_USERNAME', 'root');
        $pass = self::configString('env.USERS_DB_PASSWORD', '');

        $this->pdo = new PDO("mysql:host={$host}", $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $this->pdo;
    }

    private static function configString(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    /** Streams $from, or only its $table, into $to inside sites-db, imported as $account and every DEFINER given to it. */
    public function copyInSitesDb(string $from, string $to, ?string $table, ImportAccount $account, string $failurePrefix): void
    {
        $this->runInCore($this->sitesDb()->sitesDbCopy($from, $to, $table, $account->user), $failurePrefix);
    }

    /** The account a copy into $database imports as: the application database's, else the one user granted on it. */
    public function importAccount(User $owner, string $database): ImportAccount
    {
        if ($database === AppDatabase::nameFor($owner)) {
            return new ImportAccount($database);
        }

        $granted = [];
        foreach ($owner->mysqlUsers()->pluck('user') as $user) {
            if (is_string($user) && isset($this->privileges()->showPrivileges($user)[$database])) {
                $granted[] = $user;
            }
        }
        if (count($granted) !== 1) {
            throw new RuntimeException(
                "Database '{$database}' cannot be copied into: a copy is imported as the database's one MariaDB account, "
                    .'and '.count($granted).' of the project\'s accounts are granted on it.'
            );
        }

        return new ImportAccount($granted[0]);
    }

    public function dropTableInSitesDb(string $database, string $table): void
    {
        $this->runInCore($this->sitesDb()->sitesDbDropTable($database, $table), 'table drop failed');
    }

    /** Core has no MySQL client, so this runs in sites-db. */
    private function sitesDb(): RootEngineComposeLines
    {
        return Privileged::engineComposeLines($this->system()->engineDirPath());
    }

    /** pipefail, so a failing dump is not hidden by gzip's success. */
    private function runInCore(string $command, string $failurePrefix): string
    {
        $process = $this->system()->runProcess(['bash', '-c', 'set -o pipefail; '.$command], [], $this->commandTimeout());
        if (! $process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new RuntimeException($failurePrefix.': '.trim($message));
        }

        return $process->getOutput();
    }

    private function commandTimeout(): int
    {
        return $this->timeout ?? self::DB_COMMAND_TIMEOUT;
    }

    private function system(): EngineSystem
    {
        return $this->system ?? throw new RuntimeException('Mysql dump/restore requires an Engine System');
    }

    /** The grammar every database name this engine copies must satisfy. */
    public static function isValidDatabaseName(string $name): bool
    {
        return preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/D', $name) === 1;
    }

    public static function assertDatabaseName(string $name): void
    {
        if (! self::isValidDatabaseName($name)) {
            throw new RuntimeException('Invalid database name');
        }
    }

    /** The grammar every table name this engine copies must satisfy. */
    public static function isValidTableName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_$]+$/', $name) === 1;
    }

    public static function assertTableName(string $name): void
    {
        if (! self::isValidTableName($name)) {
            throw new RuntimeException('Invalid table name');
        }
    }
}
