<?php

namespace Tests\Unit\Task;

use App\Http\Resources\TaskResource;
use App\Jobs\Middleware\RecordCoverage;
use App\Lib\Testing\CoverageRecorder;
use App\Models\Admin;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Unit\Task\Support\StubTaskJob;

class CoverageTokenOnTaskTest extends SqliteTaskTestCase
{
    private int $tokenId = 900010;

    protected function setUp(): void
    {
        parent::setUp();
        CoverageRecorder::reset();
        config(['app.debug' => true]);
    }

    protected function tearDown(): void
    {
        $dir = dirname(CoverageRecorder::recordingPath($this->tokenId));
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        CoverageRecorder::reset();
        parent::tearDown();
    }

    public function test_start_stamps_api_token_id_when_recording(): void
    {
        $this->fakeAuthToken($this->tokenId);
        File::ensureDirectoryExists(dirname(CoverageRecorder::recordingPath($this->tokenId)));
        File::put(CoverageRecorder::recordingPath($this->tokenId), '');

        $task = Task::start(
            jobType: StubTaskJob::class,
            queue: 'default',
            details: ['action' => 'deploy'],
        );

        $this->assertSame($this->tokenId, $task->details['api_token_id'] ?? null);
        $this->assertSame('deploy', $task->details['action'] ?? null);
    }

    public function test_start_does_not_stamp_without_recording_file(): void
    {
        $this->fakeAuthToken($this->tokenId);

        $task = Task::start(
            jobType: StubTaskJob::class,
            queue: 'default',
            details: ['action' => 'deploy'],
        );

        $this->assertArrayNotHasKey('api_token_id', $task->details ?? []);
        $this->assertSame(['action' => 'deploy'], $task->details);
    }

    public function test_task_resource_strips_api_token_id(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $task->details = [
            'action' => 'deploy',
            'api_token_id' => $this->tokenId,
        ];
        $task->save();

        $payload = (new TaskResource($task))->toArray(Request::create('/'));

        $this->assertSame(['action' => 'deploy'], $payload['details']);
        $this->assertArrayNotHasKey('api_token_id', $payload['details']);
    }

    public function test_task_resource_nulls_details_when_only_token_remains(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $task->details = ['api_token_id' => $this->tokenId];
        $task->save();

        $payload = (new TaskResource($task))->toArray(Request::create('/'));

        $this->assertNull($payload['details']);
    }

    public function test_attach_task_registers_record_coverage_middleware(): void
    {
        $job = new StubTaskJob();
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RecordCoverage::class, $middleware[0]);
    }

    public function test_record_coverage_middleware_passes_through_without_recording(): void
    {
        $task = Task::start(
            jobType: StubTaskJob::class,
            queue: 'default',
            details: ['api_token_id' => $this->tokenId],
        );
        $job = new StubTaskJob();
        $job->attachTask($task);

        $result = (new RecordCoverage())->handle($job, static fn ($j) => 'ok');

        $this->assertSame('ok', $result);
        $dumps = CoverageRecorder::dumpsDir($this->tokenId);
        $this->assertFalse(is_dir($dumps) && count(glob($dumps . '/*.rawcov') ?: []) > 0);
    }

    public function test_api_get_task_hides_api_token_id(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Authenticate::class);

        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $task->details = [
            'reason' => 'manual',
            'api_token_id' => $this->tokenId,
        ];
        $task->save();

        $response = $this->getJson('/api/tasks/' . $task->id);
        $response->assertStatus(200);
        $response->assertJsonPath('data.details.reason', 'manual');
        $response->assertJsonMissingPath('data.details.api_token_id');
    }

    private function fakeAuthToken(int $tokenId): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => $tokenId,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'coverage-test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);

        $admin = new Admin();
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
    }
}
