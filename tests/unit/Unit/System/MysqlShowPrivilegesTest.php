<?php

namespace Tests\Unit\System;

use App\System\Services\Mysql\Privileges;
use PDO;
use PDOStatement;
use Tests\TestCase;

/** How SHOW GRANTS output is read into database => privileges. */
class MysqlShowPrivilegesTest extends TestCase
{
    public function test_each_database_grant_is_read_and_usage_and_odd_rows_are_skipped(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute');
        $statement->method('fetch')->willReturnOnConsecutiveCalls(
            ["GRANT USAGE ON *.* TO `bob_wp`@`%` IDENTIFIED BY PASSWORD '*AB'"],
            ['GRANT SELECT, INSERT, UPDATE ON `bob_wp`.* TO `bob_wp`@`%`'],
            'not a row',
            ['no index zero' => 'GRANT ALL ON `x`.* TO `bob_wp`@`%`'],
            [42],
            ['GRANT ALL PRIVILEGES ON `bob\_shop`.* TO `bob_wp`@`%` WITH GRANT OPTION'],
            ['GRANT SELECT ON `bob_wp`.`wp_posts` TO `bob_wp`@`%`'],
            false,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('SHOW GRANTS FOR `bob_wp`@`%`')->willReturn($statement);

        $this->assertSame([
            'bob_wp' => 'SELECT, INSERT, UPDATE',
            'bob\_shop' => 'ALL PRIVILEGES',
        ], (new Privileges($pdo, '%'))->showPrivileges('bob_wp'));
    }

    public function test_a_user_with_no_grants_has_none(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willThrowException(new \PDOException("SQLSTATE[42000]: There is no such grant defined for user 'bob' on host '%'"));
        $statement->expects($this->never())->method('fetch');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $this->assertSame([], (new Privileges($pdo, '%'))->showPrivileges('bob'));
    }

    public function test_any_other_failure_comes_through(): void
    {
        $failure = new \PDOException('SQLSTATE[HY000]: server has gone away');
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException($failure);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        try {
            (new Privileges($pdo, 'localhost'))->showPrivileges('bob');
            $this->fail('the failure should come through');
        } catch (\PDOException $e) {
            $this->assertSame($failure, $e);
        }
    }

    public function test_an_empty_answer_is_no_grants(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $this->assertSame([], (new Privileges($pdo, '%'))->showPrivileges('bob'));
    }
}
