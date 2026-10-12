<?php

namespace Tests\Unit\Deploy;

use App\Exceptions\ProblemException;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A first deploy cancelled while it runs ends its job cleanly: no failed job, no ERROR line. */
class DeployProjectCancelTest extends TestCase
{
    use InMemoryDatabase;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->name = 'dpc' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        DeployLogger::deleteUserLogs($this->name);
        parent::tearDown();
    }

    private function job(User $user, \Throwable $failure): DeployProject
    {
        return new class ($user->username, $failure, (int) $user->id) extends DeployProject {
            public function __construct(string $username, private \Throwable $failure, int $userId)
            {
                parent::__construct($username, userId: $userId);
            }

            protected function runDeployment(User $user, ?DeployLogger $logger, callable $keepTail): void
            {
                throw $this->failure;
            }
        };
    }

    private function task(): Task
    {
        return Task::start(jobType: DeployProject::class, queue: 'default', username: $this->name);
    }

    public function test_a_cancelled_deploy_ends_the_job_without_throwing(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $task = $this->task();

        $this->job($user, ProblemException::deploy('deploy_cancelled', 'Deployment cancelled by user', 'cloning'))
            ->attachTask($task)->handle();

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_failed_deploy_still_fails_the_job(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $task = $this->task();

        try {
            $this->job($user, ProblemException::deploy('deploy_failed', 'npm ci exited 1', 'running'))->attachTask($task)->handle();
            $this->fail('A failed deploy ended the job cleanly.');
        } catch (\RuntimeException $e) {
            $this->assertSame('npm ci exited 1', $e->getMessage());
        }
        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
    }
}
