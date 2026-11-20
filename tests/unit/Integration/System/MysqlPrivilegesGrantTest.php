<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Privileges;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * Granting a user a database replaces what it held there; revoking all clears every grant.
 */
class MysqlPrivilegesGrantTest extends TestCase
{
    public function test_a_new_grant_replaces_the_one_the_user_held_on_the_database(): void
    {
        $executed = [];
        $privileges = $this->privileges(['GRANT SELECT ON `alice_db`.* TO `alice_u`@`%`'], $executed);

        $privileges->updatePrivileges('alice_u', 'alice_db', 'ALL PRIVILEGES');

        $this->assertSame([
            "REVOKE ALL ON `alice_db`.* FROM `alice_u`@'%'",
            'FLUSH PRIVILEGES',
            "GRANT ALL PRIVILEGES ON `alice_db`.* TO \"alice_u\"@'%'",
            'FLUSH PRIVILEGES',
        ], $executed);
    }

    public function test_a_first_grant_revokes_nothing(): void
    {
        $executed = [];
        $privileges = $this->privileges(['GRANT USAGE ON *.* TO `alice_u`@`%`'], $executed);

        $privileges->updatePrivileges('alice_u', 'alice_db', 'SELECT, INSERT');

        $this->assertSame([
            "GRANT SELECT, INSERT ON `alice_db`.* TO \"alice_u\"@'%'",
            'FLUSH PRIVILEGES',
        ], $executed);
    }

    public function test_revoking_all_clears_every_grant_of_the_user(): void
    {
        $executed = [];
        $privileges = $this->privileges([], $executed);

        $privileges->revokeAll('alice_u');

        $this->assertSame(["REVOKE ALL ON *.* FROM `alice_u`@'%'", 'FLUSH PRIVILEGES'], $executed);
    }

    /**
     * A Privileges whose `SHOW GRANTS` answers with $grantLines; every other statement is recorded in $executed.
     *
     * @param  list<string>  $grantLines
     * @param  list<string>  $executed
     */
    private function privileges(array $grantLines, array &$executed): Privileges
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
                if (! str_starts_with($query, 'SHOW GRANTS')) {
                    $this->executed[] = $query;

                    return parent::prepare('SELECT 1');
                }

                $rows = array_map(fn (string $line): string => 'SELECT '.$this->quote($line), $this->grantLines);

                return parent::prepare($rows === [] ? 'SELECT 1 WHERE 0' : implode(' UNION ALL ', $rows));
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
