<?php

namespace Tests\Unit\Http;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Http\Middleware\Authenticate;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\ProjectRebuild;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /projects/{username}/rebuild and /deploy-archive answer at once with a
 * task, and a second call while a deploy runs names that task instead of
 * starting another. The NDJSON stream still runs the deploy in the request.
 */
class ProjectRebuildHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    /** @var list<string> */
    private array $usernames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();

        $this->home = sys_get_temp_dir() . '/pa-rebuild-http-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/project', 0777, true);
        file_put_contents($this->home . '/project/app.zip', 'PK');
        $home = $this->home;
        $this->app->instance(ProjectRebuild::class, new class ($home) extends ProjectRebuild {
            public function __construct(private string $home)
            {
            }

            protected function homeOf(User $user): string
            {
                return $this->home;
            }
        });
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        exec('rm -rf ' . escapeshellarg($this->home));
        Setting::clearRuntimeSettings();
        app(DeployPlanContext::class)->clear();
        app(RecipeChoiceContext::class)->clear();
        parent::tearDown();
    }

    private function dindUser(): User
    {
        $username = 'rb' . bin2hex(random_bytes(4));
        $this->usernames[] = $username;

        return $this->makeUser($username, ['template' => 'dind', 'env_vars' => ['KEEP' => 'yes']]);
    }

    public function test_a_rebuild_answers_202_with_a_task_and_queues_the_deploy(): void
    {
        $user = $this->dindUser();

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", [
            'env_vars' => ['ADDED' => 'one'],
            'recipe' => 'php',
        ]);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.job_type', RebuildProject::class);
        $response->assertJsonPath('data.status', Task::STATUS_QUEUED);
        $response->assertJsonPath('data.username', $user->username);
        $response->assertJsonPath('data.details.action', 'rebuild');
        $taskId = $response->json('data.id');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->taskId === $taskId
            && $job->username === $user->username
            && $job->action === ProjectRebuild::REBUILD
            && $job->zipPath === null
            && $job->recipe === 'php');
        // Applied before the 202, so a bad value is still a 422 and a good one is there for the job.
        $this->assertEquals(['KEEP' => 'yes', 'ADDED' => 'one'], $user->refresh()->getEnvVars());
    }

    public function test_an_archive_deploy_answers_202_with_a_task(): void
    {
        $user = $this->dindUser();

        $response = $this->postJson("/api/projects/{$user->username}/deploy-archive", ['zip_path' => '/project/app.zip']);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.details.action', 'deploy_archive');
        $response->assertJsonPath('data.details.zip_path', '/project/app.zip');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === ProjectRebuild::DEPLOY_ARCHIVE
            && $job->zipPath === '/project/app.zip');
        // The archive stays where the client put it, for the worker to read.
        $this->assertFileExists($this->home . '/project/app.zip');
    }

    public function test_an_archive_that_is_not_there_is_a_422_and_queues_nothing(): void
    {
        $user = $this->dindUser();

        foreach (['/project/none.zip', '/project/app.rar'] as $zipPath) {
            $response = $this->postJson("/api/projects/{$user->username}/deploy-archive", ['zip_path' => $zipPath]);
            $this->assertSame(422, $response->getStatusCode(), $zipPath);
            $response->assertJsonPath('problems.0.field', 'zip_path');
        }
        $response = $this->postJson("/api/projects/{$user->username}/rebuild", ['zip_path' => '/project/none.zip']);
        $this->assertSame(422, $response->getStatusCode());

        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_bad_plan_is_a_422_and_queues_nothing(): void
    {
        $user = $this->dindUser();

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", ['recipe' => 'Not A Recipe']);

        $this->assertSame(422, $response->getStatusCode());
        $response->assertJsonValidationErrors('recipe');
        Queue::assertNothingPushed();
    }

    public function test_a_second_call_while_the_first_is_queued_gets_409_with_its_task(): void
    {
        $user = $this->dindUser();
        $first = $this->postJson("/api/projects/{$user->username}/rebuild");
        $this->assertSame(202, $first->getStatusCode());
        $taskId = $first->json('data.id');

        foreach (['rebuild', 'deploy-archive'] as $endpoint) {
            $again = $this->postJson("/api/projects/{$user->username}/{$endpoint}", [
                'zip_path' => '/project/app.zip',
                'env_vars' => ['ADDED' => 'two'],
            ]);
            $this->assertSame(409, $again->getStatusCode(), $endpoint);
            $again->assertJsonPath('task_id', $taskId);
            $this->assertStringContainsString("GET /tasks/{$taskId}", (string) $again->json('message'));
            // A task whose job was lost would block for good: the answer says how to clear it.
            $this->assertStringContainsString("POST /tasks/{$taskId}/cancel", (string) $again->json('message'));
        }

        Queue::assertPushedTimes(RebuildProject::class, 1);
        $this->assertSame(['KEEP' => 'yes'], $user->refresh()->getEnvVars(), 'a refused call changes nothing');
    }

    public function test_a_create_still_deploying_blocks_a_rebuild(): void
    {
        $user = $this->dindUser();
        $create = Task::start(jobType: DeployProject::class, queue: 'default', username: $user->username);
        $create->markRunning('job-1');

        $response = $this->postJson("/api/projects/{$user->username}/rebuild");

        $this->assertSame(409, $response->getStatusCode());
        $response->assertJsonPath('task_id', $create->id);
        Queue::assertNothingPushed();
    }

    public function test_a_finished_task_does_not_block(): void
    {
        $user = $this->dindUser();
        Task::start(jobType: RebuildProject::class, queue: 'default', username: $user->username)->markFailed('earlier');

        $this->assertSame(202, $this->postJson("/api/projects/{$user->username}/rebuild")->getStatusCode());
    }

    /** A deploy started without a task -- the CLI, a push, POST /users -- holds only the lock. */
    public function test_a_deploy_holding_the_lock_gets_409_without_a_task(): void
    {
        $user = $this->dindUser();
        $running = DeployLogger::start($user->username);

        try {
            $response = $this->postJson("/api/projects/{$user->username}/rebuild");
        } finally {
            $running->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'message' => "A deploy of this project is already running. Follow it with GET /projects/{$user->username}/deploy-log.",
            'task_id' => null,
        ], $response->json());
        Queue::assertNothingPushed();
    }

    public function test_the_ndjson_stream_still_runs_the_deploy_in_the_request(): void
    {
        $user = $this->dindUser();
        $ran = new \ArrayObject();
        $this->app->instance(ProjectRebuild::class, new class ($ran) extends ProjectRebuild {
            public function __construct(private \ArrayObject $ran)
            {
            }

            public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath, array $git = []): array
            {
                $this->ran[] = [$action, $zipPath, $logger !== null];
                $logger?->info('built in the request');
                $logger?->finish(DeployLogger::STATUS_SUCCESS);

                return [];
            }
        });

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", [], ['X-Deploy-Stream' => 'ndjson']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $frames = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_values(array_filter(explode("\n", $response->streamedContent())))
        );
        $this->assertSame('start', $frames[0]['type']);
        $this->assertContains('built in the request', array_column($frames, 'msg'));
        $finish = $frames[array_key_last($frames)];
        $this->assertSame('finish', $finish['type']);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, $finish['status']);
        $this->assertSame(['username' => $user->username, 'domain' => $user->domain], $finish['user']);
        $this->assertSame([[ProjectRebuild::REBUILD, null, true]], $ran->getArrayCopy());
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
    }

    /** The stream was asked for: without a log to stream from, say so rather than answer with a task. */
    public function test_a_stream_with_no_deploy_log_is_an_error_not_a_task(): void
    {
        $user = $this->dindUser();
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild {
            public function openLog(User $user, string $action): ?DeployLogger
            {
                return null;
            }
        });

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", ['env_vars' => ['ADDED' => 'x']], ['X-Deploy-Stream' => 'ndjson']);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('leave out X-Deploy-Stream', (string) $response->json('message'));
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(['KEEP' => 'yes'], $user->refresh()->getEnvVars(), 'nothing was started, nothing changed');
    }

    /** The check and the queueing are one step: another request cannot look in between. */
    public function test_the_check_and_the_queueing_hold_the_project_exclusively(): void
    {
        $user = $this->dindUser();
        $lock = DeployLogPaths::userDir($user->username) . '/.queue.lock';
        $heldElsewhere = null;

        $task = app(ProjectRebuild::class)->whileIdle($user, function () use ($user, $lock, &$heldElsewhere) {
            $other = fopen($lock, 'c');
            $heldElsewhere = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);

            return app(ProjectRebuild::class)->queue($user, ProjectRebuild::REBUILD, null, null, null);
        });

        $this->assertTrue($heldElsewhere, 'a second request would wait for this one');
        $this->assertSame(Task::STATUS_QUEUED, $task->status);
        $free = fopen($lock, 'c');
        $this->assertTrue(flock($free, LOCK_EX | LOCK_NB), 'released afterwards');
        fclose($free);
        $this->assertSame(409, $this->postJson("/api/projects/{$user->username}/rebuild")->getStatusCode());
    }

    /** A deploy with no task took the lock between the check and the stream: the same 409 as the check's. */
    public function test_a_stream_that_loses_the_lock_race_answers_like_the_check(): void
    {
        $user = $this->dindUser();
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild {
            public function openLog(User $user, string $action): ?DeployLogger
            {
                throw new DeployAlreadyRunningException('A deployment is already running for this user.');
            }
        });

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", [], ['X-Deploy-Stream' => 'ndjson']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'message' => "A deploy of this project is already running. Follow it with GET /projects/{$user->username}/deploy-log.",
            'task_id' => null,
        ], $response->json());
    }

    /** Without the project's lock the check could race another request: refuse rather than go on. */
    public function test_a_lock_that_cannot_be_taken_is_a_503_and_queues_nothing(): void
    {
        $user = $this->dindUser();
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild {
            protected function lockQueue(DeployLogPaths $paths)
            {
                return null;
            }
        });

        $response = $this->postJson("/api/projects/{$user->username}/rebuild", ['env_vars' => ['ADDED' => 'x']]);

        $this->assertSame(503, $response->getStatusCode());
        Queue::assertNothingPushed();
        $this->assertSame(['KEEP' => 'yes'], $user->refresh()->getEnvVars());
    }

    /** The 409 is an answer, not a fault: it is not reported with a stack trace. */
    public function test_the_busy_answer_is_not_reported(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new DeployBusyException('shop', null)));
        $this->assertTrue($handler->shouldReport(new \RuntimeException('a real fault')));
    }

    public function test_an_unknown_stream_value_is_refused_before_anything_starts(): void
    {
        $user = $this->dindUser();

        $response = $this->postJson("/api/projects/{$user->username}/deploy-archive", ['zip_path' => '/project/app.zip'], ['X-Deploy-Stream' => 'sse']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(DeployLogger::readLatestFor($user->username), 'no deploy log was opened');
        Queue::assertNothingPushed();
    }
}
