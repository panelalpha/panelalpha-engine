<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Privileges;
use PDO;
use PDOStatement;
use Tests\TestCase;

class MysqlPrivilegesTest extends TestCase
{
    public function test_a_table_grant_is_not_read_as_a_grant_on_its_database(): void
    {
        $privileges = $this->privileges([
            'GRANT USAGE ON *.* TO `alice_u`@`%`',
            'GRANT SELECT ON `alice_db`.`wp_posts` TO `alice_u`@`%`',
        ]);

        $this->assertSame([], $privileges->showPrivileges('alice_u'));
    }

    public function test_a_database_grant_is_kept_next_to_a_table_grant_on_the_same_database(): void
    {
        $privileges = $this->privileges([
            'GRANT USAGE ON *.* TO `alice_u`@`%`',
            'GRANT INSERT ON `alice_db`.* TO `alice_u`@`%`',
            'GRANT SELECT ON `alice_db`.`wp_posts` TO `alice_u`@`%`',
        ]);

        $this->assertSame(['alice_db' => 'INSERT'], $privileges->showPrivileges('alice_u'));
    }

    public function test_a_user_with_only_a_table_grant_has_no_database_grant_to_revoke(): void
    {
        $executed = [];
        $privileges = $this->privileges(['GRANT SELECT ON `alice_db`.`wp_posts` TO `alice_u`@`%`'], $executed);

        $privileges->revokeIfExists('alice_u', 'alice_db');

        $this->assertSame([], $executed);
    }

    public function test_a_database_grant_is_revoked(): void
    {
        $executed = [];
        $privileges = $this->privileges(['GRANT ALL PRIVILEGES ON `alice_db`.* TO `alice_u`@`%`'], $executed);

        $privileges->revokeIfExists('alice_u', 'alice_db');

        $this->assertSame(["REVOKE ALL ON `alice_db`.* FROM `alice_u`@'%'", 'FLUSH PRIVILEGES'], $executed);
    }

    /**
     * A Privileges whose `SHOW GRANTS` answers with $grantLines; every other statement is recorded in $executed.
     *
     * @param  list<string>  $grantLines
     * @param  list<string>  $executed
     */
    private function privileges(array $grantLines, array &$executed = []): Privileges
    {
        $pdo = new class ($grantLines, $executed) extends PDO {
            /**
             * @param  list<string>  $grantLines
             * @param  list<string>  $executed
             */
            public function __construct(private array $grantLines, private array &$executed)
            {
                parent::__construct('sqlite::memory:');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $rows = array_map(fn (string $line): string => 'SELECT ' . $this->quote($line), $this->grantLines);

                return parent::prepare(implode(' UNION ALL ', $rows));
            }

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return 0;
            }
        };

        return new Privileges($pdo, '%');
    }
}
