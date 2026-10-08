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

    public function test_dump_to_gzip_runs_mysqldump_pipeline(): void
    {
        $recorded = [];
        $system = $this->recordingSystem($recorded, successful: true);
        $mysql = new Mysql($system);

        $mysql->dumpToGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertCount(1, $recorded);
        $this->assertSame(['sh', '-c'], array_slice($recorded[0]['cmd'], 0, 2));
        $this->assertStringContainsString('mysqldump', $recorded[0]['cmd'][2]);
        $this->assertStringContainsString('alice_app', $recorded[0]['cmd'][2]);
        $this->assertSame('secret', $recorded[0]['env']['MYSQL_PWD']);
    }

    public function test_restore_from_gzip_runs_mysql_pipeline(): void
    {
        $recorded = [];
        $system = $this->recordingSystem($recorded, successful: true);
        $mysql = new Mysql($system);

        $mysql->restoreFromGzip('alice_app', '/tmp/alice_app.sql.gz');

        $this->assertCount(1, $recorded);
        $this->assertStringContainsString('gunzip', $recorded[0]['cmd'][2]);
        $this->assertStringContainsString('mysql', $recorded[0]['cmd'][2]);
        $this->assertStringContainsString('alice_app', $recorded[0]['cmd'][2]);
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
    private function recordingSystem(array &$recorded, bool $successful): System
    {
        return new class ($recorded, $successful) extends System {
            /** @param list<array{cmd: list<string>, env: array<string, string>}> $recorded */
            public function __construct(
                private array &$recorded,
                private bool $successful,
            ) {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                $this->recorded[] = ['cmd' => $argv, 'env' => $env];
                $successful = $this->successful;

                return new class ($successful) extends Process {
                    public function __construct(private bool $ok)
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
                        return '';
                    }
                };
            }
        };
    }
}
