<?php

namespace Tests\Integration\Jobs;

use App\Jobs\PushState;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A push the projects refuse, and what the failure handler settles: the
 * target's push flag and error, and the half-copied `.incoming` it removes.
 */
class PushStateJobTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-push-job-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $client = $this->dir . '/pa-root';
        file_put_contents($client, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($this->dir . '/calls') . "\nexit 0\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_push_between_different_templates_fails_its_task(): void
    {
        $from = $this->makeUser('push-from', ['template' => 'dind']);
        $to = $this->makeUser('push-to', ['template' => 'classic']);
        $task = Task::start(jobType: PushState::class, queue: 'default', username: $to->username);

        try {
            (new PushState($from->username, $to->username))->attachTask($task)->handle();
            $this->fail('The job did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame("Projects 'push-from' (dind) and 'push-to' (classic) have different templates.", $e->getMessage());
        }

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame("Projects 'push-from' (dind) and 'push-to' (classic) have different templates.", $task->details['error']);
    }

    public function test_a_failed_push_marks_the_target_and_removes_its_incoming_copy(): void
    {
        $from = $this->makeUser('push-src', ['template' => 'dind']);
        $to = $this->makeUser('push-dst', ['template' => 'dind', 'async_status' => ['push' => 'running', 'source' => 'api']]);
        $task = Task::start(jobType: PushState::class, queue: 'default', username: $to->username);
        $task->markRunning('job-1');

        (new PushState($from->username, $to->username))->attachTask($task)->failed(new \RuntimeException('rsync died'));

        $to->refresh();
        $this->assertSame('failed', $to->asyncStatus()['push']);
        $this->assertSame('api', $to->asyncStatus()['source']);
        $this->assertSame('rsync died', $to->getDetails()['error']);
        $this->assertSame('rsync died', $task->refresh()->details['error']);
        $home = rtrim($to->project()->homeDirPath(), '/');
        $this->assertContains("files.removeTree -- {$home}/.incoming", $this->calls());
    }

    public function test_a_target_home_the_broker_cannot_name_is_logged_and_the_failure_still_settles(): void
    {
        $to = $this->makeUser('..', ['template' => 'dind', 'async_status' => ['push' => 'running']]);
        Log::spy();

        (new PushState('push-src-gone', $to->username))->failed(new \RuntimeException('rsync died'));

        $this->assertSame('failed', $to->refresh()->asyncStatus()['push']);
        $this->assertSame([], $this->calls());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'PushState failed cleanup could not remove .incoming'
                && $context['target'] === '..',
        )->once();
    }

    public function test_a_failure_with_neither_project_left_only_fails_the_task(): void
    {
        $task = Task::start(jobType: PushState::class, queue: 'default');

        (new PushState('push-none-a', 'push-none-b'))->attachTask($task)->failed(new \RuntimeException('gone'));

        $this->assertSame('gone', $task->refresh()->details['error']);
        $this->assertSame([], $this->calls());
    }

    public function test_a_failure_leaves_an_already_settled_task_as_it_is(): void
    {
        $to = $this->makeUser('push-late', ['template' => 'dind']);
        $task = Task::start(jobType: PushState::class, queue: 'default', username: $to->username);
        $task->markCompleted();

        (new PushState('push-late-src', $to->username))->attachTask($task)->failed(new \RuntimeException('late'));

        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertSame('failed', $to->refresh()->asyncStatus()['push']);
        $this->assertNull(User::findByUsername('push-late-src'));
    }

    /** @return list<string> */
    private function calls(): array
    {
        $file = $this->dir . '/calls';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }
}
