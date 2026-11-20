<?php

namespace Tests\Integration\Console;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Http\Middleware\Authenticate;
use App\Jobs\ApplyEnvVars;
use App\Jobs\CreateBackup;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\TaskTrigger;
use App\Lib\Upload\ArchiveSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `pae project:deploy-archive`: the archive is checked, put into the project and its deploy queued as the API queues it;
 * the command then prints the deploy log the worker writes and exits with the task's verdict.
 */
class DeployArchiveCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const DROPPED = '/.panelalpha/archives/.deploy-archive-test/win.zip';

    private string $archive;

    /** @var list<array{0: string, 1: mixed}> */
    private array $calls = [];

    private ?\Throwable $assertFails = null;

    /** @var ?\Closure(Task): void what the worker did to the task by the time the command reads it */
    private ?\Closure $worker = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        Queue::fake();
        $this->archive = sys_get_temp_dir() . '/pa-archive-test-' . bin2hex(random_bytes(4)) . '/win.zip';
        mkdir(dirname($this->archive));
        file_put_contents($this->archive, 'PK');
        $this->bindDoubles();
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        @rmdir(dirname($this->archive));
        DeployLogger::deleteUserLogs('alice');
        app(RecipeChoiceContext::class)->clear();
        parent::tearDown();
    }

    public function test_the_deploy_is_queued_as_the_api_queues_it_and_the_archive_left_to_the_job(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--recipe' => 'php', '--no-wait' => true])
            ->expectsOutputToContain('as task 1.')
            ->assertExitCode(0);

        Queue::assertPushed(RebuildProject::class, 1);
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === ProjectRebuild::DEPLOY_ARCHIVE
            && $job->zipPath === self::DROPPED
            && $job->recipe === 'php'
            && $job->ownsArchive);
        $task = Task::query()->sole();
        $this->assertSame(RebuildProject::class, $task->job_type);
        $this->assertSame(Task::STATUS_QUEUED, $task->status);
        $this->assertSame(TaskTrigger::CLI, $task->details['trigger']);
        $this->assertSame(self::DROPPED, $task->details['zip_path']);
        $this->assertNull(app(RecipeChoiceContext::class)->get(), 'the recipe travels with the job, not in this process');
        $this->assertSame(['put', 'assertArchive'], array_column($this->calls, 0), 'the job removes the archive, not the command');
    }

    /** As an API caller's upload is: the deploy names the archive by its file name. */
    public function test_the_archive_is_put_into_the_project_under_the_name_the_operator_gave(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--no-wait' => true])
            ->assertExitCode(0);

        $this->assertSame(['put', [$this->archive, 'win.zip']], $this->calls[0]);
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => basename((string) $job->zipPath) === 'win.zip');
    }

    public function test_env_vars_are_merged_into_the_project_before_the_deploy_is_queued(): void
    {
        $this->makeUser('alice', ['template' => 'dind', 'env_vars' => ['KEEP' => '1']]);

        $this->artisan('project:deploy-archive', [
            '--project' => 'alice', '--file' => $this->archive, '--no-wait' => true, '--env-var' => ['FOO=bar'],
        ])->assertExitCode(0);

        $this->assertSame(['KEEP' => '1', 'FOO' => 'bar'], User::findByUsername('alice')?->getEnvVars());
    }

    public function test_a_deploy_that_completes_prints_the_workers_log_and_exits_0(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->worker = fn (Task $task) => $this->settle($task, Task::STATUS_COMPLETED);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain('built by the worker')
            ->expectsOutputToContain('Deployed ' . $this->archive . ' to alice.')
            ->assertExitCode(0);
    }

    public function test_a_failed_deploy_exits_1_with_the_tasks_error_on_one_line(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->worker = fn (Task $task) => $this->settle($task, Task::STATUS_FAILED, "Build failed:\n  npm ci exited 1");

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain('built by the worker')
            ->expectsOutputToContain('Build failed: npm ci exited 1')
            ->doesntExpectOutputToContain('Deployed ')
            ->assertExitCode(1);
    }

    public function test_a_cancelled_deploy_exits_1(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->worker = fn (Task $task) => $this->settle($task, Task::STATUS_CANCELLED);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain('The deploy was cancelled.')
            ->assertExitCode(1);
    }

    public function test_a_task_cancelled_before_a_worker_took_it_exits_1(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->worker = static fn (Task $task) => $task->cancelIfQueued();

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain('The deploy was cancelled.')
            ->assertExitCode(1);
    }

    public function test_a_deploy_no_worker_takes_is_given_up_at_the_timeout_and_exits_1(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--timeout' => 1])
            ->expectsOutputToContain('Waiting for a queue worker to start task 1.')
            ->expectsOutputToContain('Not finished after 1s; stopped following. The deploy goes on as task 1.')
            ->assertExitCode(1);

        $this->assertSame(Task::STATUS_QUEUED, Task::query()->sole()->status);
    }

    public function test_quiet_deploy_waits_for_the_verdict_without_the_log(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->worker = fn (Task $task) => $this->settle($task, Task::STATUS_COMPLETED);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--quiet-deploy' => true])
            ->doesntExpectOutputToContain('built by the worker')
            ->expectsOutputToContain('Deployed ' . $this->archive . ' to alice.')
            ->assertExitCode(0);
    }

    public function test_a_busy_project_is_refused_at_once_and_the_archive_removed(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $running = Task::start(
            jobType: RebuildProject::class,
            queue: 'default',
            username: 'alice',
            details: ['action' => ProjectRebuild::DEPLOY_ARCHIVE],
        );
        $running->forceFill(['status' => Task::STATUS_RUNNING])->save();

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain("Project 'alice' is busy: archive deploy task {$running->id} is running. Wait for it to finish, or cancel it if it is stuck.")
            ->doesntExpectOutputToContain('DeployBusyException')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertSame(['put', 'assertArchive', 'remove'], array_column($this->calls, 0));
    }

    /** The apply `system:api-access:allow` queues holds the account: the refusal names it, as it is no deploy. */
    public function test_the_apply_api_access_allow_queued_is_named_in_the_refusal(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $apply = Task::start(
            jobType: ApplyEnvVars::class,
            queue: 'default',
            username: 'alice',
            details: ['action' => ApiAccess::TASK_ACTION, 'project' => 'alice'],
        );
        $apply->forceFill(['status' => Task::STATUS_RUNNING])->save();

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain("Project 'alice' is busy: API access apply task {$apply->id} is running. Wait for it to finish, or cancel it if it is stuck.")
            ->doesntExpectOutputToContain('ApplyEnvVars')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertSame(['put', 'assertArchive', 'remove'], array_column($this->calls, 0));
    }

    public function test_a_pending_backup_is_refused_the_same_way(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $backup = Task::start(jobType: CreateBackup::class, queue: 'default', username: 'alice');

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])
            ->expectsOutputToContain("Project 'alice' is busy: backup task {$backup->id} is queued. Cancel task {$backup->id} or wait for it to finish.")
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertSame(['put', 'assertArchive', 'remove'], array_column($this->calls, 0));
    }

    public function test_an_api_deploy_meanwhile_answers_409_with_the_queued_task(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->withoutMiddleware(Authenticate::class);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--no-wait' => true])->assertExitCode(0);
        $queued = Task::query()->sole();

        foreach (['rebuild', 'deploy-archive'] as $endpoint) {
            $response = $this->postJson("/api/projects/alice/{$endpoint}", ['zip_path' => self::DROPPED]);
            $this->assertSame(409, $response->getStatusCode(), $endpoint);
            $response->assertJsonPath('task_id', $queued->id);
            $response->assertJsonPath('code', DeployBusyException::TASK);
            $response->assertJsonPath('message', "Project 'alice' is busy: archive deploy task {$queued->id} is queued. Wait for it to finish, or cancel it if it is stuck.");
        }
        Queue::assertPushed(RebuildProject::class, 1);
    }

    public function test_a_refused_archive_is_removed_and_nothing_queued(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->assertFails = ValidationException::withMessages(['zip_path' => 'Not a .zip or .tar.gz archive.']);

        try {
            $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive])->run();
            $this->fail('A refused archive should reach the caller.');
        } catch (ValidationException) {
        }

        Queue::assertNothingPushed();
        $this->assertSame(['put', 'assertArchive', 'remove'], array_column($this->calls, 0));
    }

    public function test_a_classic_project_is_refused_before_anything_is_put(): void
    {
        $this->makeUser('bob', ['template' => 'default']);

        $this->expectException(ValidationException::class);
        try {
            $this->artisan('project:deploy-archive', ['--project' => 'bob', '--file' => $this->archive])->run();
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function test_a_git_project_is_refused_before_anything_is_put(): void
    {
        $this->makeUser('carol', ['template' => 'dind', 'git_repo' => 'https://github.com/owner/repo.git']);

        try {
            $this->artisan('project:deploy-archive', ['--project' => 'carol', '--file' => $this->archive])->run();
            $this->fail('A git project should be refused.');
        } catch (ProblemException $e) {
            $this->assertSame('archive_on_git_project', $e->problems[0]['code'] ?? null);
        }
        $this->assertSame([], $this->calls);
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badInvocations')]
    public function test_a_bad_invocation_is_a_plain_error_and_nothing_is_put(array $options, string $message): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $options = array_map(fn (mixed $value): mixed => $value === '@archive' ? $this->archive : $value, $options);

        $this->artisan('project:deploy-archive', $options)
            ->expectsOutputToContain($message)
            ->assertExitCode(1);
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInvocations(): array
    {
        return [
            'no project' => [['--file' => '@archive'], '--project is required'],
            'no file' => [['--project' => 'alice'], '--file is required'],
            'missing file' => [['--project' => 'alice', '--file' => '/nonexistent/app.zip'], 'Archive not found'],
            'unsupported format' => [['--project' => 'alice', '--file' => __FILE__], 'Unsupported archive format'],
            'malformed env var' => [['--project' => 'alice', '--file' => '@archive', '--env-var' => ['NOEQUALS']], 'expects KEY=VALUE'],
        ];
    }

    /** What a worker leaves: the deploy log it wrote, named on the task, and the task's end; the error only on the task. */
    private function settle(Task $task, string $status, ?string $error = null): void
    {
        $logger = DeployLogger::start('alice');
        $logger->info('built by the worker');
        $logger->finish(match ($status) {
            Task::STATUS_COMPLETED => DeployLogger::STATUS_SUCCESS,
            Task::STATUS_CANCELLED => DeployLogger::STATUS_CANCELLED,
            default => DeployLogger::STATUS_FAILED,
        });

        $details = array_merge($task->details ?? [], ['deploy_id' => $logger->getDeployId()], $error !== null ? ['error' => $error] : []);
        $task->forceFill(['status' => $status, 'details' => $details])->save();
    }

    private function bindDoubles(): void
    {
        $test = $this;
        $this->app->instance(ArchiveDrop::class, new class ($test) extends ArchiveDrop {
            public function __construct(private DeployArchiveCommandTest $test)
            {
            }

            public function put(User $user, string $file, string $name, string $dir = self::TARGET_DIR): string
            {
                $this->test->record('put', [$file, $name]);

                return $dir . '/.deploy-archive-test/' . $name;
            }

            public function remove(User $user, string $zipPath): void
            {
                $this->test->record('remove', $zipPath);
            }
        });
        $this->app->instance(ProjectRebuild::class, new class ($test) extends ProjectRebuild {
            public function __construct(private DeployArchiveCommandTest $test)
            {
            }

            public function assertArchive(User $user, string $zipPath): void
            {
                $this->test->record('assertArchive', $zipPath);
                $this->test->failAssertIfAsked();
            }

            public function queue(
                User $user,
                string $action,
                ?string $zipPath,
                ?DeployPlan $plan,
                ?string $recipe,
                array $git = [],
                bool $ownsArchive = false,
                array $details = [],
                ?ArchiveSource $archive = null,
            ): Task {
                $task = parent::queue($user, $action, $zipPath, $plan, $recipe, $git, $ownsArchive, $details, $archive);
                $this->test->work($task);

                return $task;
            }
        });
    }

    public function record(string $call, mixed $argument): void
    {
        $this->calls[] = [$call, $argument];
    }

    public function failAssertIfAsked(): void
    {
        if ($this->assertFails !== null) {
            throw $this->assertFails;
        }
    }

    public function work(Task $task): void
    {
        if ($this->worker !== null) {
            ($this->worker)($task);
        }
    }
}
