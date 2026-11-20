<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployLogReader;
use App\Lib\Deploy\DeployLog\DeployRequest;
use App\Lib\Deploy\DeployLog\LogStorage;
use App\Models\Task;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Who asked for a deploy, on its task and in its log. */
class DeployRequestTest extends TestCase
{
    use InMemoryDatabase;

    private const DEPLOY = '20260101-000000-aaaaaa';

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->username = 'dr' . bin2hex(random_bytes(4));
        LogStorage::ensureDirectory(DeployLogPaths::userDir($this->username));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_task_gets_the_deploy_id_and_the_log_names_the_task(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username, details: ['action' => 'git_pull', 'trigger' => 'mcp']);

        DeployRequest::recordTask($this->logger(), $task);

        $this->assertSame(self::DEPLOY, $task->refresh()->details['deploy_id']);
        $this->assertSame(["Deploy requested (trigger: mcp, action: git_pull, task: {$task->id})"], $this->messages());
    }

    public function test_a_deploy_without_a_task_names_the_action_it_was_asked_for(): void
    {
        DeployRequest::record($this->logger(), 'rebuild');

        $this->assertSame(['Deploy requested (trigger: cli, action: rebuild)'], $this->messages());
    }

    public function test_no_log_records_nothing(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username, details: ['action' => 'rebuild']);

        DeployRequest::recordTask(null, $task);
        DeployRequest::record(null, 'rebuild');

        $this->assertArrayNotHasKey('deploy_id', $task->refresh()->details);
    }

    private function logger(): DeployLogger
    {
        return DeployLogger::forDeploy($this->username, self::DEPLOY);
    }

    /** @return list<string> */
    private function messages(): array
    {
        $lines = (new DeployLogReader((new DeployLogPaths($this->username))->log(self::DEPLOY)))->head(10);

        return array_column($lines, 'msg');
    }
}
