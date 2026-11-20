<?php

namespace Tests\Integration\Jobs;

use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\DeployLogTail;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What a deploy job writes onto its task around a pipeline it does not run:
 * the first log line, a partial outcome, a failure that kept the log's tail.
 */
class DeployProjectOutcomesTest extends TestCase
{
    use InMemoryDatabase;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->name = 'dpo' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        DeployLogger::deleteUserLogs($this->name);
        parent::tearDown();
    }

    public function test_a_partial_deploy_of_a_classic_project_records_its_warnings(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->task();
        $job = $this->job($user, function (User $user, ?DeployLogger $logger): void {
            $this->assertNull($logger);
            $user->setDetails(['deployment_status' => 'partial', 'deployment_warnings' => ['cron not installed']]);
            $user->save();
        });

        $job->attachTask($task)->handle();

        $task->refresh();
        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertSame('partial', $task->details['deployment_status']);
        $this->assertSame(['cron not installed'], $task->details['deployment_warnings']);
    }

    public function test_a_partial_deploy_without_warnings_records_only_the_status(): void
    {
        $user = $this->makeUser($this->name);
        $task = $this->task();

        $this->job($user, function (User $user): void {
            $user->setDetails(['deployment_status' => 'partial']);
            $user->save();
        })->attachTask($task)->handle();

        $task->refresh();
        $this->assertSame('partial', $task->details['deployment_status']);
        $this->assertArrayNotHasKey('deployment_warnings', $task->details);
    }

    public function test_a_git_deploy_opens_its_log_with_the_repository_without_its_credentials(): void
    {
        $user = $this->makeUser($this->name, ['git_repo' => 'https://deployer:s3cret@github.com/acme/app.git']);
        $task = $this->task();

        $this->job($user, function (User $user, ?DeployLogger $logger): void {
            $this->assertNotNull($logger);
        })->attachTask($task)->handle();

        $logs = implode("\n", TaskLog::query()->where('task_id', $task->id)->pluck('log')->all());
        $this->assertStringContainsString('Deploy started (source: git, repo: ', $logs);
        $this->assertStringContainsString('github.com/acme/app.git', $logs);
        $this->assertStringNotContainsString('s3cret', $logs);
    }

    public function test_a_deploy_without_a_task_runs_and_records_nothing(): void
    {
        $user = $this->makeUser($this->name);
        $ran = false;

        $this->job($user, function () use (&$ran): void {
            $ran = true;
        })->handle();

        $this->assertTrue($ran);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_failure_whose_log_tail_was_kept_points_the_message_at_the_task_log(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $task = $this->task();
        $job = $this->job($user, function (User $user, ?DeployLogger $logger, callable $keepTail): void {
            $logger->info('Building image');
            $keepTail($logger);
            throw ValidationException::withMessages(['build' => ['npm ci exited 1.']]);
        });

        try {
            $job->attachTask($task)->handle();
            $this->fail('A failed deploy ended the job cleanly.');
        } catch (\RuntimeException $e) {
            $this->assertSame(DeployLogTail::retarget('npm ci exited 1.'), $e->getMessage());
        }

        $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
        $this->assertStringContainsString('Building image', implode("\n", TaskLog::query()->where('task_id', $task->id)->pluck('log')->all()));
    }

    public function test_a_failed_job_fails_an_unfinished_task_with_a_reason(): void
    {
        $task = $this->task();

        (new DeployProject($this->name))->attachTask($task)->failed(null);

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Deploy job failed', $task->details['error']);
    }

    private function task(): Task
    {
        return Task::start(jobType: DeployProject::class, queue: 'default', username: $this->name);
    }

    /** @param callable(User, ?DeployLogger, callable): void $pipeline */
    private function job(User $user, callable $pipeline): DeployProject
    {
        return new class ($user->username, $pipeline, (int) $user->id) extends DeployProject {
            /** @var callable */
            private $pipeline;

            public function __construct(string $username, callable $pipeline, int $userId)
            {
                parent::__construct($username, userId: $userId);
                $this->pipeline = $pipeline;
            }

            protected function runDeployment(User $user, ?DeployLogger $logger, callable $keepTail): void
            {
                ($this->pipeline)($user, $logger, $keepTail);
            }
        };
    }
}
