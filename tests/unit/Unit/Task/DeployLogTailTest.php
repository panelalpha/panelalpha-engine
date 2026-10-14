<?php

namespace Tests\Unit\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\DeployLogTail;
use App\Lib\Task\TaskPruner;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Facades\Schema;

class DeployLogTailTest extends SqliteTaskTestCase
{
    private const BUILD_FAILED = 'Failed to start app: A build step failed (exit code 1). The full output is in the deploy log.';

    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function failedDeploy(int $buildLines): DeployLogger
    {
        $username = 'tail-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        for ($i = 1; $i <= $buildLines; $i++) {
            $logger->dim("build line {$i}");
        }
        $logger->error('npm ERR! missing script: build');
        $logger->finish(DeployLogger::STATUS_FAILED, self::BUILD_FAILED);

        return $logger;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloads(): array
    {
        return TaskLog::query()->orderBy('id')->pluck('log')
            ->map(static fn (string $log): array => json_decode($log, true))
            ->all();
    }

    public function test_failed_deploy_tail_is_copied_with_levels_and_stages(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');

        $this->assertTrue(DeployLogTail::preserve($task, $this->failedDeploy(10)));

        $payloads = $this->payloads();
        $this->assertStringStartsWith('Last ', $payloads[0]['msg']);
        $messages = array_column($payloads, 'msg');
        $this->assertContains('build line 10', $messages);
        $this->assertContains('npm ERR! missing script: build', $messages);
        $last = end($payloads);
        $this->assertSame('error', $last['level']);
        $this->assertStringStartsWith('Deploy failed: Failed to start app', $last['msg']);

        $dim = array_values(array_filter($payloads, static fn (array $p): bool => $p['msg'] === 'build line 10'))[0];
        $this->assertSame('dim', $dim['level']);
        $this->assertSame('running', $dim['stage']);
        $this->assertTrue($dim[DeployLogTail::FLAG]);
    }

    public function test_copy_is_bounded_to_the_last_lines(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');

        $this->assertTrue(DeployLogTail::preserve($task, $this->failedDeploy(400), 5000));

        // Header plus the bound, and it is the end of the log that survives.
        $this->assertSame(DeployLogTail::MAX_LINES + 1, TaskLog::count());
        $messages = array_column($this->payloads(), 'msg');
        $this->assertNotContains('build line 1', $messages);
        $this->assertContains('build line 400', $messages);

        TaskLog::query()->delete();
        DeployLogTail::preserve($task, $this->failedDeploy(50), 10);
        $this->assertSame(11, TaskLog::count());
    }

    public function test_copied_dim_lines_survive_the_terminal_trim(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');
        TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => time(), 'stage' => 'running', 'level' => 'dim', 'msg' => 'streamed noise']),
        ]);
        DeployLogTail::preserve($task, $this->failedDeploy(20));
        $task->markFailed('boom');

        TaskPruner::trimTask($task->refresh());

        $messages = array_column($this->payloads(), 'msg');
        $this->assertNotContains('streamed noise', $messages);
        $this->assertContains('build line 20', $messages);
    }

    public function test_copy_failure_is_swallowed(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');
        $logger = $this->failedDeploy(5);
        Schema::drop('task_logs');

        $this->assertFalse(DeployLogTail::preserve($task, $logger));
        $this->assertFalse(DeployLogTail::preserve(null, $logger));
        $this->assertFalse(DeployLogTail::preserve($task, null));
    }

    public function test_retarget_points_at_the_task_log_instead_of_the_deleted_deploy_log(): void
    {
        $this->assertSame(
            "Failed to start app: A build step failed (exit code 1). The last lines of the deploy log are in this task's log.",
            DeployLogTail::retarget(self::BUILD_FAILED),
        );
        $this->assertStringNotContainsString(
            'resolver output is in the deploy log',
            DeployLogTail::retarget('Composer could not resolve. The full resolver output is in the deploy log.'),
        );
        $this->assertSame(
            "Failed to start app: port in use. The last lines of the deploy log are in this task's log.",
            DeployLogTail::retarget('Failed to start app: port in use.'),
        );
    }
}
