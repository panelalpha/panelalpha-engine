<?php

namespace Tests\Integration\Jobs;

use App\Exceptions\NotFoundException;
use App\Jobs\RenameMysqlDatabase;
use App\Models\MysqlDatabase;
use App\Models\Task;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class RenameMysqlDatabaseJobTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_rename_for_a_deleted_project_fails_its_task(): void
    {
        $task = $this->task('rn-gone');

        $e = $this->failedRun(new RenameMysqlDatabase('rn-gone', 'rn_a', 'rn_b'), $task);

        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame("Project 'rn-gone' not found.", $e->getMessage());
    }

    public function test_a_rename_of_a_database_the_project_does_not_own_fails_its_task(): void
    {
        $user = $this->makeUser('rnown');
        MysqlDatabase::create(['user_id' => $user->id, 'database' => 'rnown_kept']);
        $task = $this->task($user->username);

        $e = $this->failedRun(new RenameMysqlDatabase($user->username, 'rnown_other', 'rnown_new'), $task);

        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame("MySQL database 'rnown_other' not found.", $e->getMessage());
        $this->assertSame("MySQL database 'rnown_other' not found.", $task->refresh()->details['error']);
    }

    public function test_a_rename_the_database_server_cannot_take_fails_and_keeps_the_row(): void
    {
        config(['env.USERS_DB_HOST' => 'localhost', 'env.USERS_DB_USERNAME' => 'root', 'env.USERS_DB_PASSWORD' => '']);
        $user = $this->makeUser('rndb');
        MysqlDatabase::create(['user_id' => $user->id, 'database' => 'rndb_a']);
        $task = $this->task($user->username);

        $e = $this->failedRun(new RenameMysqlDatabase($user->username, 'rndb_a', 'rndb_b'), $task);

        $this->assertInstanceOf(\PDOException::class, $e);
        $this->assertSame(1, MysqlDatabase::query()->where('database', 'rndb_a')->count());
        $this->assertSame(0, MysqlDatabase::query()->where('database', 'rndb_b')->count());
    }

    private function failedRun(RenameMysqlDatabase $job, Task $task): \Throwable
    {
        try {
            $job->attachTask($task)->handle();
        } catch (\Throwable $e) {
            $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);

            return $e;
        }

        $this->fail('The job did not throw.');
    }

    private function task(string $username): Task
    {
        return Task::start(jobType: RenameMysqlDatabase::class, queue: 'default', username: $username);
    }
}
