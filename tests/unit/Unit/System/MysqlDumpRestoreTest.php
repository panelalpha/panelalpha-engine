<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\Mysql;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MysqlDumpRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'env.USERS_DB_HOST' => 'db.example.test',
            'env.USERS_DB_USERNAME' => 'backup_user',
            'env.USERS_DB_PASSWORD' => 'secret',
        ]);
    }

    public function test_the_dump_runs_in_sites_db_and_is_gzipped_in_core(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "-- Dump completed on 2026-10-09 12:00:00\n"));

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertCount(2, $recorded);
        $this->assertSame(['bash', '-c'], array_slice($recorded[0]['cmd'], 0, 2));
        $script = $recorded[0]['cmd'][2];
        $this->assertStringStartsWith('set -o pipefail; ', $script);
        $this->assertStringContainsString("'exec' '-T' 'sites-db'", $script);
        $this->assertStringContainsString(
            escapeshellarg('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb-dump --single-transaction -uroot ' . escapeshellarg('alice_app')),
            $script,
        );
        $this->assertStringEndsWith("| gzip -c > '/tmp/alice_app.sql.gz'", $script);
        // The password stays inside sites-db: it is read there from the container's own environment.
        $this->assertStringContainsString('MYSQL_PWD="$MYSQL_ROOT_PASSWORD"', $script);
        $this->assertSame([], $recorded[0]['env']);
    }

    public function test_a_dump_without_its_completion_line_fails_the_backup(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "INSERT INTO `t` VALUES (1"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the dump of alice_app is incomplete');

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');
    }

    public function test_a_failed_dump_fails_the_backup(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mysqldump failed: boom');

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');
    }

    public function test_the_restore_feeds_the_dump_to_mariadb_in_sites_db(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true));

        $mysql->restoreFromGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertCount(1, $recorded);
        $script = $recorded[0]['cmd'][2];
        $this->assertStringStartsWith("set -o pipefail; gunzip -c '/tmp/alice_app.sql.gz' | ", $script);
        $this->assertStringContainsString("'exec' '-T' 'sites-db'", $script);
        $this->assertStringContainsString(
            escapeshellarg('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb -uroot ' . escapeshellarg('alice_app')),
            $script,
        );
    }

    public function test_a_hyphenated_database_name_reaches_mariadb_as_a_plain_argument(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "-- Dump completed on 2026-10-09 12:00:00\n"));

        $mysql->dumpToGzip('alice_my-db', '/tmp/alice_my-db.sql.gz');
        $mysql->restoreFromGzip('alice_my-db', '/tmp/alice_my-db.sql.gz');

        $dump = $recorded[0]['cmd'][2];
        $restore = $recorded[2]['cmd'][2];

        $this->assertStringContainsString(
            escapeshellarg('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb-dump --single-transaction -uroot ' . escapeshellarg('alice_my-db')),
            $dump,
        );
        $this->assertStringEndsWith("| gzip -c > '/tmp/alice_my-db.sql.gz'", $dump);
        $this->assertStringContainsString(
            escapeshellarg('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb -uroot ' . escapeshellarg('alice_my-db')),
            $restore,
        );
        $this->assertStringNotContainsString('`', $dump);
        $this->assertStringNotContainsString('`', $restore);
    }

    public function test_invalid_database_name_is_rejected(): void
    {
        $recorded = [];
        $system = $this->recordingSystem($recorded, successful: true);
        $mysql = new Mysql($system);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid database name');

        $mysql->dumpToGzip('bad;name', '/tmp/x.sql.gz');
    }

    public function test_dump_requires_system(): void
    {
        $mysql = new Mysql(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mysql dump/restore requires an Engine System');

        $mysql->dumpToGzip('alice_app', '/tmp/x.sql.gz');
    }

    /**
     * @param list<array{cmd: list<string>, env: array<string, string>}> $recorded
     */
    private function recordingSystem(array &$recorded, bool $successful, string $lastLine = ''): System
    {
        return new class ($recorded, $successful, $lastLine) extends System {
            /** @param list<array{cmd: list<string>, env: array<string, string>}> $recorded */
            public function __construct(
                private array &$recorded,
                private bool $successful,
                private string $lastLine,
            ) {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                $this->recorded[] = ['cmd' => $argv, 'env' => $env];
                $successful = $this->successful;
                $output = str_contains($argv[2] ?? '', 'tail -n 1') ? $this->lastLine : '';

                return new class ($successful, $output) extends Process {
                    public function __construct(private bool $ok, private string $out)
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->ok;
                    }

                    public function getErrorOutput(): string
                    {
                        return $this->ok ? '' : 'boom';
                    }

                    public function getOutput(): string
                    {
                        return $this->out;
                    }
                };
            }
        };
    }
}
