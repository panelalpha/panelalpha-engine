<?php

namespace App\System\Services;

use App\Support\JobBudget;
use App\Support\SitesDbCaches;
use App\System as EngineSystem;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
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
    ) {
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

    public function dumpToGzip(string $name, string $dest): void
    {
        self::assertDatabaseName($name);
        $this->runInCore(
            $this->inSitesDb('mariadb-dump --single-transaction -uroot ' . escapeshellarg($name))
            . ' | gzip -c > ' . escapeshellarg($dest),
            'mysqldump failed'
        );
        $this->assertDumpCompleted($name, $dest);
    }

    /**
     * A dump cut short still gzips cleanly; only a finished one ends with `-- Dump completed`.
     */
    private function assertDumpCompleted(string $name, string $dest): void
    {
        $last = trim($this->runInCore('gzip -cd ' . escapeshellarg($dest) . ' | tail -n 1', 'mysqldump failed'));
        if (!str_starts_with($last, '-- Dump completed')) {
            throw new RuntimeException("mysqldump failed: the dump of {$name} is incomplete");
        }
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        self::assertDatabaseName($name);
        $this->runInCore(
            'gunzip -c ' . escapeshellarg($sqlGzPath) . ' | ' . $this->inSitesDb('mariadb -uroot ' . escapeshellarg($name)),
            'database restore failed'
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

    /** Core has no MySQL client; sites-db, which holds every site database, has MariaDB's. */
    private function inSitesDb(string $command): string
    {
        $argv = [
            'sudo', 'docker', 'compose', '--project-directory', $this->system()->engineDirPath(),
            'exec', '-T', SitesDbCaches::SERVICE, 'bash', '-c',
            'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; ' . $command,
        ];

        return implode(' ', array_map('escapeshellarg', $argv));
    }

    /**
     * Run one shell script inside sites-db, on the connection the dump and the
     * restore use. Kept public so a copy can stream a dump straight into the
     * destination without staging a file.
     */
    public function runSitesDbScript(string $script, string $failurePrefix): void
    {
        $this->runInCore($this->inSitesDb($script), $failurePrefix);
    }

    /** pipefail, so a failing dump is not hidden by gzip's success. */
    private function runInCore(string $command, string $failurePrefix): string
    {
        $process = $this->system()->runProcess(['bash', '-c', 'set -o pipefail; ' . $command], [], self::DB_COMMAND_TIMEOUT);
        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new RuntimeException($failurePrefix . ': ' . trim($message));
        }

        return $process->getOutput();
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
        if (!self::isValidDatabaseName($name)) {
            throw new RuntimeException('Invalid database name');
        }
    }
}
