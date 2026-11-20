<?php

namespace Tests\Integration\System;

use App\Support\JobBudget;
use App\System;
use App\System\Services\Mysql;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MysqlDumpRestoreTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

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
        $this->assertSame(
            ['bash', '-c', "set -o pipefail; ".self::CLIENT." compose.sitesDbDump -- /opt/panelalpha/shared-hosting alice_app '' | gzip -c > '/tmp/alice_app.sql.gz'"],
            $recorded[0]['cmd'],
        );
        $this->assertSame(
            self::CLIENT." compose.sitesDbDump -- /opt/panelalpha/shared-hosting alice_app ''",
            self::request($recorded[0]['cmd'][2]),
            'the password stays inside sites-db, read there from the container\'s own environment',
        );
        $this->assertSame([], $recorded[0]['env']);
    }

    public function test_a_dump_without_its_completion_line_fails_the_backup(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "INSERT INTO `t` VALUES (1"));

        try {
            $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');
            $this->fail('the incomplete dump should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the dump of alice_app is incomplete', $e->getMessage());
        }

        $this->assertSame(['bash', '-c', "set -o pipefail; rm -f '/tmp/alice_app.sql.gz'"], $recorded[2]['cmd']);
    }

    public function test_a_failed_dump_fails_the_backup_and_removes_the_partial_file(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: false));

        try {
            $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');
            $this->fail('the dump should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('mysqldump failed: boom', $e->getMessage());
        }

        $this->assertCount(2, $recorded);
        $this->assertSame(['bash', '-c', "set -o pipefail; rm -f '/tmp/alice_app.sql.gz'"], $recorded[1]['cmd']);
    }

    public function test_a_given_timeout_caps_the_dump_and_its_completion_check(): void
    {
        $recorded = [];
        $mysql = (new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "-- Dump completed on 2026-10-09 12:00:00\n")))
            ->withTimeout(120);

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertNotEmpty($recorded);
        foreach ($recorded as $call) {
            $this->assertSame(120, $call['timeout'], 'the dump must stop at the given timeout');
        }
    }

    public function test_without_a_timeout_the_dump_uses_the_single_command_window(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "-- Dump completed on 2026-10-09 12:00:00\n"));

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertNotEmpty($recorded);
        foreach ($recorded as $call) {
            $this->assertSame(JobBudget::COMMAND_SECONDS, $call['timeout']);
        }
    }

    public function test_the_restore_feeds_the_dump_to_mariadb_in_sites_db(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true));

        $mysql->restoreFromGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertCount(1, $recorded);
        $this->assertSame(
            ['bash', '-c', "set -o pipefail; gunzip -c '/tmp/alice_app.sql.gz' | ".self::CLIENT.' compose.sitesDbRestore -- /opt/panelalpha/shared-hosting alice_app'],
            $recorded[0]['cmd'],
        );
        $this->assertSame(
            self::CLIENT.' compose.sitesDbRestore -- /opt/panelalpha/shared-hosting alice_app',
            self::request($recorded[0]['cmd'][2]),
        );
    }

    public function test_a_hyphenated_database_name_reaches_mariadb_as_a_plain_argument(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true, lastLine: "-- Dump completed on 2026-10-09 12:00:00\n"));

        $mysql->dumpToGzip('alice_my-db', '/tmp/alice_my-db.sql.gz');
        $mysql->restoreFromGzip('alice_my-db', '/tmp/alice_my-db.sql.gz');

        $this->assertSame(
            self::CLIENT." compose.sitesDbDump -- /opt/panelalpha/shared-hosting alice_my-db ''",
            self::request($recorded[0]['cmd'][2]),
        );
        $this->assertSame(
            self::CLIENT.' compose.sitesDbRestore -- /opt/panelalpha/shared-hosting alice_my-db',
            self::request($recorded[2]['cmd'][2]),
        );
        $this->assertStringEndsWith("| gzip -c > '/tmp/alice_my-db.sql.gz'", $recorded[0]['cmd'][2]);
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
     * @param list<array{cmd: list<string>, env: array<string, string>, timeout: int}> $recorded
     */
    private function recordingSystem(array &$recorded, bool $successful, string $lastLine = ''): System
    {
        return new class ($recorded, $successful, $lastLine) extends System {
            /** @param list<array{cmd: list<string>, env: array<string, string>, timeout: int}> $recorded */
            public function __construct(
                private array &$recorded,
                private bool $successful,
                private string $lastLine,
            ) {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                $this->recorded[] = ['cmd' => $argv, 'env' => $env, 'timeout' => $timeout];
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

    /** The broker request in a shell line, after `set -o pipefail;` or a pipe, up to the next pipe. */
    private static function request(string $script): string
    {
        foreach (explode(' | ', substr($script, strlen('set -o pipefail; '))) as $part) {
            if (str_starts_with($part, self::CLIENT)) {
                return $part;
            }
        }

        throw new \RuntimeException("no broker request in {$script}");
    }
}
