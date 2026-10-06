<?php

namespace Tests\Unit\Deploy;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProblemException;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Lib\Task\TaskPruner;
use App\Models\Domain;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\System\Project;
use App\System\Project\Deployment\DeploymentWorkflow;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\System\Project\Deployment\RecordingDisposition;
use Tests\Unit\System\Project\Deployment\RecordingMechanics;

/**
 * The queued rebuild or archive deploy: what it leaves on its task is all a
 * client that got the 202 ever sees of it.
 */
class RebuildProjectJobTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $usernames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        app(DeployPlanContext::class)->clear();
        app(RecipeChoiceContext::class)->clear();
        parent::tearDown();
    }

    private function dindUser(): User
    {
        $username = 'rj' . bin2hex(random_bytes(4));
        $this->usernames[] = $username;

        return $this->makeUser($username, ['template' => 'dind']);
    }

    /**
     * @param \Closure(User, ?DeployLogger, ?string): void $run
     */
    private function rebuildThat(\Closure $run, ?\Closure $openLog = null): void
    {
        $this->app->instance(ProjectRebuild::class, new class ($run, $openLog) extends ProjectRebuild {
            public function __construct(private \Closure $runs, private ?\Closure $opens)
            {
            }

            public function openLog(User $user, string $action): ?DeployLogger
            {
                return $this->opens !== null ? ($this->opens)($user) : parent::openLog($user, $action);
            }

            public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath): void
            {
                ($this->runs)($user, $logger, $zipPath);
            }
        });
    }

    private function runJob(RebuildProject $job, User $user, string $action = ProjectRebuild::REBUILD): Task
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $user->username, details: [
            'username' => $user->username,
            'action' => $action,
        ]);
        $job->attachTask($task);
        try {
            $this->app->call([$job, 'handle']);
        } catch (\Throwable) {
            // runTask() has recorded it on the task, which is what is asserted.
        }

        return $task->refresh();
    }

    public function test_the_job_is_a_deploy_with_one_try_on_the_default_queue(): void
    {
        $job = new RebuildProject('acme', ProjectRebuild::DEPLOY_ARCHIVE, '/project/app.zip');

        $this->assertSame(1, $job->tries);
        $this->assertSame(7200, $job->timeout);
        $this->assertSame('default', $job->queue);
        $this->assertTrue((new Task(['job_type' => RebuildProject::class]))->isDeploy());
        $this->assertSame(TaskPruner::KEEP_DEPLOY, TaskPruner::keepForJobType(RebuildProject::class));
    }

    public function test_a_rebuild_that_works_completes_the_task_with_its_verdict(): void
    {
        $user = $this->dindUser();
        $this->rebuildThat(static function (User $user, ?DeployLogger $logger): void {
            $logger?->info('built by the worker');
            $logger?->finish(DeployLogger::STATUS_PARTIAL, 'not public');
            $user->setDetails(['deployment_status' => 'partial', 'deployment_warnings' => ['not public']]);
            $user->save();
        });

        $task = $this->runJob(new RebuildProject($user->username), $user);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertSame('partial', $task->details['deployment_status']);
        $this->assertSame(['not public'], $task->details['deployment_warnings']);
        $this->assertSame(DeployLogger::readLatestFor($user->username)['id'], $task->details['deploy_id'], 'the deploy it ran is named');
        $this->assertSame(getmypid(), $task->details[Task::WORKER]['pid'], 'and the worker that ran it');
        $logged = TaskLog::query()->where('task_id', $task->id)->pluck('log')->implode("\n");
        $this->assertStringContainsString('built by the worker', $logged, 'the deploy log is teed onto the task');
    }

    public function test_the_plan_and_recipe_reach_the_worker(): void
    {
        $user = $this->dindUser();
        $seen = new \ArrayObject();
        $this->rebuildThat(static function () use ($seen): void {
            $seen['plan'] = app(DeployPlanContext::class)->get()?->toArray();
            $seen['recipe'] = app(RecipeChoiceContext::class)->get();
        }, static fn (): ?DeployLogger => null);

        $task = $this->runJob(new RebuildProject($user->username, ProjectRebuild::REBUILD, null, ['upgrade' => []], 'php'), $user);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertSame(['upgrade' => []], $seen['plan']);
        $this->assertSame('php', $seen['recipe']);
    }

    public function test_a_failed_deploy_records_its_error_code_stage_and_log_offset(): void
    {
        $user = $this->dindUser();
        $this->rebuildThat(static function (): void {
            throw ProblemException::deploy('app_did_not_start', 'Failed to start app: exited with code 1', 'running');
        }, static fn (): ?DeployLogger => null);

        $task = $this->runJob(new RebuildProject($user->username), $user);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Failed to start app: exited with code 1', $task->details['error']);
        $this->assertSame([[
            'field' => 'deploy',
            'code' => 'app_did_not_start',
            'message' => 'Failed to start app: exited with code 1',
            'stage' => 'running',
            'deploy_log_offset' => 0,
        ]], $task->details['problems']);
        $this->assertSame('rebuild', $task->details['action'], 'what the request recorded stays');
    }

    public function test_a_refused_archive_is_recorded_under_zip_path(): void
    {
        $user = $this->dindUser();
        $this->rebuildThat(static function (): void {
            throw ValidationException::withMessages(['zip_path' => 'Archive contains a symbolic link.']);
        }, static fn (): ?DeployLogger => null);

        $task = $this->runJob(new RebuildProject($user->username, ProjectRebuild::DEPLOY_ARCHIVE, '/project/app.zip'), $user, ProjectRebuild::DEPLOY_ARCHIVE);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Archive contains a symbolic link.', $task->details['error']);
        $this->assertSame('zip_path', $task->details['problems'][0]['field']);
        $this->assertSame('zip_path_invalid', $task->details['problems'][0]['code']);
    }

    public function test_a_cancelled_deploy_cancels_the_task(): void
    {
        $user = $this->dindUser();
        $this->rebuildThat(static function (): void {
            throw ProblemException::deploy('deploy_cancelled', 'Deploy cancelled by user', 'running');
        }, static fn (): ?DeployLogger => null);

        $task = $this->runJob(new RebuildProject($user->username), $user);

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame('deploy_cancelled', $task->details['problems'][0]['code']);
    }

    /**
     * A deploy started without a task (the CLI, a push) took the lock after
     * this one was queued: this task fails, nothing runs twice.
     */
    public function test_a_deploy_that_won_the_race_fails_this_task(): void
    {
        $user = $this->dindUser();
        $ran = false;
        $this->rebuildThat(static function () use (&$ran): void {
            $ran = true;
        }, static function (): never {
            throw new DeployAlreadyRunningException('A deployment is already running for this user.');
        });

        $task = $this->runJob(new RebuildProject($user->username), $user);

        $this->assertFalse($ran);
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('deploy_already_running', $task->details['problems'][0]['code']);
    }

    public function test_a_project_deleted_before_the_job_ran_cancels_the_task(): void
    {
        $user = $this->dindUser();
        $this->rebuildThat(static function (): void {
            throw new \LogicException('must not run');
        });
        $job = new RebuildProject($user->username);
        $user->delete();

        $task = $this->runJob($job, $user);

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
    }

    /** The real lock: a worker that finds another deploy holding it fails, and leaves that deploy alone. */
    public function test_the_worker_takes_the_real_lock(): void
    {
        $user = $this->dindUser();
        $other = DeployLogger::start($user->username);
        $this->rebuildThat(static function (): void {
            throw new \LogicException('must not run');
        });

        try {
            $task = $this->runJob(new RebuildProject($user->username), $user);
        } finally {
            $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($user->username)['status']);
            $other->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('deploy_already_running', $task->details['problems'][0]['code']);
    }

    /**
     * The real archive deploy and its error handling, only the mechanics below
     * the workflow recorded: what a refused archive and a failed start leave
     * on the task.
     */
    private function rebuildOnProject(Project $project): void
    {
        $this->app->instance(ProjectRebuild::class, new class ($project) extends ProjectRebuild {
            public function __construct(private Project $fake)
            {
            }

            protected function project(User $user): Project
            {
                return $this->fake;
            }
        });
    }

    private function archiveDeployOn(User $user, RecordingMechanics $mechanics): Task
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('deployment')->andReturn(DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition()));
        $project->shouldNotReceive('system');
        $this->rebuildOnProject($project);

        return $this->runJob(new RebuildProject($user->username, ProjectRebuild::DEPLOY_ARCHIVE, '/project/app.zip'), $user, ProjectRebuild::DEPLOY_ARCHIVE);
    }

    public function test_a_refused_archive_fails_the_task_under_zip_path_and_closes_its_log(): void
    {
        $user = $this->dindUser();
        $mechanics = new RecordingMechanics($user, new Domain());
        $mechanics->ingestArchiveException = new \InvalidArgumentException('Archive contains a symbolic link, which could redirect extraction outside the project directory.');

        $task = $this->archiveDeployOn($user, $mechanics);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('zip_path', $task->details['problems'][0]['field']);
        $this->assertSame('zip_path_invalid', $task->details['problems'][0]['code']);
        $this->assertStringContainsString('symbolic link', $task->details['error']);
        $latest = DeployLogger::readLatestFor($user->username);
        $this->assertSame($latest['id'], $task->details['deploy_id']);
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertNotNull($latest['finished_at']);
        $this->assertSame(['ingestArchive', 'settle'], $mechanics->calls);
    }

    public function test_an_archive_that_does_not_start_fails_the_task_with_its_stage(): void
    {
        $user = $this->dindUser();
        $mechanics = new RecordingMechanics($user, new Domain());
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: Cannot find module '/app/server.js'\n"];

        $task = $this->archiveDeployOn($user, $mechanics);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $problem = $task->details['problems'][0];
        $this->assertSame('deploy', $problem['field']);
        $this->assertSame('running', $problem['stage']);
        $this->assertSame(0, $problem['deploy_log_offset']);
        $this->assertStringStartsWith('Failed to start app', $task->details['error']);
        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::readLatestFor($user->username)['status']);
        $this->assertSame('failed', $user->refresh()->getDeploymentStatus(), 'the project records the failure too');
    }

    /** task_cancel on a running rebuild stops its deploy, and the task ends cancelled, not failed. */
    public function test_cancelling_a_running_rebuild_cancels_its_deploy(): void
    {
        $user = $this->dindUser();
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('rebuildFromSource')->once()->andReturnUsing(function (?DeployLogger $logger): void {
            $logger->stage(DeployLogger::STAGE_RUNNING);
            $killer = Mockery::mock(ProcessTreeKiller::class);
            $killer->shouldNotReceive('kill');
            $running = Task::query()->where('job_type', RebuildProject::class)->firstOrFail();
            $this->assertTrue((new TaskCanceller($killer))->cancel($running)['cancelled']);
            // What the pipeline does at its next step once the log says cancelled.
            $logger->throwIfCancelled();
        });
        $this->rebuildOnProject($project);

        $task = $this->runJob(new RebuildProject($user->username), $user);

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame('deploy_cancelled', $task->details['problems'][0]['code']);
        $latest = DeployLogger::readLatestFor($user->username);
        $this->assertSame(DeployLogger::STATUS_CANCELLED, $latest['status']);
        $this->assertNotNull($latest['finished_at']);
    }
}
