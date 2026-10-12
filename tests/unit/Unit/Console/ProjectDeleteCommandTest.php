<?php

namespace Tests\Unit\Console;

use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `project:delete` with no one to confirm refuses unless --force says so,
 * instead of skipping the delete and exiting 0.
 */
class ProjectDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('keepme');
    }

    public function test_no_interaction_without_force_fails_and_deletes_nothing(): void
    {
        $exit = Artisan::call('project:delete', ['project' => ['keepme'], '--no-interaction' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame('Deleting without confirmation requires --force.', trim(Artisan::output()));
        $this->assertTrue(User::existsByUsername('keepme'));
    }

    public function test_no_interaction_with_all_and_without_force_fails_too(): void
    {
        $exit = Artisan::call('project:delete', ['--all' => true, '--no-interaction' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --force', Artisan::output());
        $this->assertTrue(User::existsByUsername('keepme'));
    }

    public function test_an_operator_answering_no_still_exits_0(): void
    {
        $this->artisan('project:delete', ['project' => ['keepme']])
            ->expectsConfirmation('User `keepme` will be completely deleted from server, are you sure?', 'no')
            ->assertExitCode(0);

        $this->assertTrue(User::existsByUsername('keepme'));
    }

    /** A PHP Error deleting one project is reported like any failure, and the next one is still deleted. */
    public function test_an_error_deleting_one_project_does_not_stop_the_rest(): void
    {
        $first = 'delerr' . bin2hex(random_bytes(3));
        $second = 'delnext' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(function () use ($first, $second): void {
            DeployLogger::deleteUserLogs($first);
            DeployLogger::deleteUserLogs($second);
        });
        $this->makeUser($first);
        $this->makeUser($second);
        $this->runningTask($first);
        $this->runningTask($second);
        Task::retrieved(static function (Task $task) use ($first): void {
            throw $task->username === $first ? new \Error('type confusion') : new \RuntimeException('host refused');
        });

        $exit = Artisan::call('project:delete', ['project' => [$first, $second], '--force' => true]);

        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("ERROR deleting user `{$first}`: type confusion", $output);
        $this->assertStringContainsString("ERROR deleting user `{$second}`: host refused", $output, 'the loop went on past the Error');
    }

    private function runningTask(string $username): void
    {
        $task = Task::start(jobType: DeployProject::class, queue: 'default', username: $username);
        $this->assertTrue($task->markRunning('job-' . $username));
    }
}
