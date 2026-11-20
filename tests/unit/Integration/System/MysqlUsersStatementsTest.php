<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Users;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * The account statements a project's MySQL users are managed with, bound to the host the engine grants on.
 */
class MysqlUsersStatementsTest extends TestCase
{
    public function test_a_user_exists_when_mysql_user_holds_its_name(): void
    {
        $users = new Users($this->server(['alice_u', 'bob_u']), '%');

        $this->assertTrue($users->userExists('alice_u'));
        $this->assertFalse($users->userExists('carol_u'));
    }

    public function test_a_user_is_created_on_the_host_with_its_password_bound(): void
    {
        $pdo = $this->server();

        (new Users($pdo, '%'))->createUser('alice_u', 's3cret');

        $this->assertSame([["CREATE USER ?@'%' IDENTIFIED BY ?", 'alice_u', 's3cret']], $this->statements($pdo));
    }

    public function test_a_user_is_dropped_on_the_host(): void
    {
        $pdo = $this->server();

        (new Users($pdo, 'localhost'))->deleteUser('alice_u');

        $this->assertSame([["DROP USER ?@'localhost'", 'alice_u', null]], $this->statements($pdo));
    }

    public function test_a_user_is_renamed_on_the_same_host(): void
    {
        $pdo = $this->server();

        (new Users($pdo, '%'))->renameUser('alice_u', 'bob_u');

        $this->assertSame([["RENAME USER ?@'%' TO ?@'%'", 'alice_u', 'bob_u']], $this->statements($pdo));
    }

    public function test_a_password_change_is_flushed(): void
    {
        $pdo = $this->server();

        (new Users($pdo, '%'))->changeUserPassword('alice_u', 'n3w');

        $this->assertSame([
            ["ALTER USER ?@'%' IDENTIFIED BY ?", 'alice_u', 'n3w'],
            ['FLUSH PRIVILEGES', null, null],
        ], $this->statements($pdo));
    }

    public function test_killing_sessions_quotes_the_user_name(): void
    {
        $pdo = $this->server();

        (new Users($pdo, '%'))->killSessions("al'ice");

        $this->assertSame([["KILL CONNECTION USER 'al''ice'@'%'", null, null]], $this->statements($pdo));
    }

    /**
     * A SQLite stand-in: `mysql.user` holds $users, every other statement lands in `statements` with its bindings.
     *
     * @param  list<string>  $users
     */
    private function server(array $users = []): PDO
    {
        $pdo = new class extends PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                parent::exec('CREATE TABLE statements (query TEXT, first TEXT, second TEXT)');
                parent::exec("ATTACH DATABASE ':memory:' AS mysql");
                parent::exec('CREATE TABLE mysql.user (user TEXT)');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_starts_with($query, 'SELECT') || str_starts_with($query, 'INSERT INTO mysql.user')) {
                    return parent::prepare($query, $options);
                }

                $bound = array_fill(0, substr_count($query, '?'), '?');
                $values = array_pad($bound, 2, 'NULL');

                return parent::prepare('INSERT INTO statements VALUES ('.$this->quote($query).', '.implode(', ', $values).')');
            }

            public function exec(string $statement): int|false
            {
                return parent::exec('INSERT INTO statements VALUES ('.$this->quote($statement).', NULL, NULL)');
            }
        };

        $insert = $pdo->prepare('INSERT INTO mysql.user VALUES (?)');
        foreach ($users as $user) {
            $insert->execute([$user]);
        }

        return $pdo;
    }

    /** @return list<array{0: string, 1: ?string, 2: ?string}> */
    private function statements(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT query, first, second FROM statements ORDER BY rowid');

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_NUM);
    }
}
