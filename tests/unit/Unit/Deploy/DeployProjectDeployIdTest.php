<?php

namespace Tests\Unit\Deploy;

use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The create task names the deploy it runs, as a rebuild task does. */
class DeployProjectDeployIdTest extends TestCase
{
    use InMemoryDatabase;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->name = 'dpi' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        DeployLogger::deleteUserLogs($this->name);
        parent::tearDown();
    }

    /**
     * @param \ArrayObject<string, mixed> $seen
     */
    private function job(User $user, \ArrayObject $seen): DeployProject
    {
        return new class ($user->username, $seen, (int) $user->id) extends DeployProject {
            /**
             * @param \ArrayObject<string, mixed> $seen
             */
            public function __construct(string $username, private \ArrayObject $seen, int $userId)
            {
                parent::__construct($username, userId: $userId);
            }

            protected function runDeployment(User $user, ?DeployLogger $logger, callable $keepTail): void
            {
                $this->seen['logger'] = $logger?->getDeployId();
                $this->seen['task'] = Task::query()->whereKey($this->taskId)->first()?->details['deploy_id'] ?? null;
            }
        };
    }

    private function task(): Task
    {
        return Task::start(jobType: DeployProject::class, queue: 'default', username: $this->name);
    }

    public function test_the_create_task_names_its_deploy_while_it_runs(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $task = $this->task();
        $seen = new \ArrayObject();

        $this->job($user, $seen)->attachTask($task)->handle();

        $this->assertNotEmpty($seen['logger']);
        $this->assertSame($seen['logger'], $seen['task'], 'the id is on the task before the pipeline runs');
        $this->assertSame($seen['logger'], $task->refresh()->details['deploy_id']);
        $this->assertSame(DeployLogger::readLatestFor($this->name)['id'], $task->details['deploy_id'], 'the log deploy-log serves');
    }

    public function test_a_create_without_a_deploy_log_names_no_deploy(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'default']);
        $task = $this->task();
        $seen = new \ArrayObject();

        $this->job($user, $seen)->attachTask($task)->handle();

        $this->assertNull($seen['logger']);
        $this->assertArrayNotHasKey('deploy_id', $task->refresh()->details ?? []);
    }
}
