<?php

namespace Tests\Integration\System;

use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\ImportAccount;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Tables;
use App\System\Services\Mysql\Users;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The users-db connection the statement helpers share, and the sites-db copy and drop that run outside it.
 */
class MysqlConnectionTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    public function test_every_helper_runs_on_the_one_open_connection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("ATTACH DATABASE ':memory:' AS mysql");
        $pdo->exec('CREATE TABLE mysql.user (user TEXT)');
        $pdo->exec("INSERT INTO mysql.user VALUES ('alice_u')");
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT, data_length INTEGER, index_length INTEGER)');
        $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('alice_db', 'wp_posts', 'BASE TABLE', 30, 12)");
        $mysql = new Mysql();
        (new \ReflectionProperty(Mysql::class, 'pdo'))->setValue($mysql, $pdo);

        $this->assertInstanceOf(Databases::class, $mysql->databases());
        $this->assertSame(42, $mysql->databases()->getDatabaseSize('alice_db'));
        $this->assertInstanceOf(Users::class, $mysql->users());
        $this->assertTrue($mysql->users()->userExists('alice_u'));
        $this->assertInstanceOf(Tables::class, $mysql->tables());
        $this->assertSame(['wp_posts'], $mysql->tables()->names('alice_db'));
        $this->assertInstanceOf(Privileges::class, $mysql->privileges());
    }

    public function test_the_connection_is_opened_from_the_users_db_settings(): void
    {
        config([
            'env.USERS_DB_HOST' => 'localhost;unix_socket=/nonexistent/panelalpha-test/mysqld.sock',
            'env.USERS_DB_USERNAME' => 'root',
            'env.USERS_DB_PASSWORD' => 'secret',
        ]);

        $this->expectException(PDOException::class);

        (new Mysql())->databases();
    }

    public function test_a_copy_streams_from_one_database_into_the_other_in_sites_db(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true));

        $mysql->copyInSitesDb('alice_db', 'bob_db', 'wp_posts', new ImportAccount('bob_u'), 'copy failed');

        $this->assertCount(1, $recorded);
        $this->assertSame(
            ['bash', '-c', 'set -o pipefail; '.self::CLIENT.' compose.sitesDbCopy -- /opt/panelalpha/shared-hosting alice_db bob_db wp_posts bob_u'],
            $recorded[0],
        );
    }

    public function test_a_failed_copy_fails_with_the_callers_prefix(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copy of alice_db failed: boom');

        $mysql->copyInSitesDb('alice_db', 'bob_db', null, new ImportAccount('bob_u'), 'copy of alice_db failed');
    }

    public function test_a_table_is_dropped_in_sites_db(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: true));

        $mysql->dropTableInSitesDb('bob_db', 'wp_posts');

        $this->assertSame(
            ['bash', '-c', 'set -o pipefail; '.self::CLIENT.' compose.sitesDbDropTable -- /opt/panelalpha/shared-hosting bob_db wp_posts'],
            $recorded[0],
        );
    }

    public function test_a_failed_drop_names_the_drop(): void
    {
        $recorded = [];
        $mysql = new Mysql($this->recordingSystem($recorded, successful: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('table drop failed: boom');

        $mysql->dropTableInSitesDb('bob_db', 'wp_posts');
    }

    /** @param  list<list<string>>  $recorded */
    private function recordingSystem(array &$recorded, bool $successful): System
    {
        return new class ($recorded, $successful) extends System {
            /** @param  list<list<string>>  $recorded */
            public function __construct(private array &$recorded, private bool $successful) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->recorded[] = is_array($cmd) ? $cmd : [$cmd];

                return new class ($this->successful) extends Process {
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
                        return $this->ok ? '' : "boom\n";
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
