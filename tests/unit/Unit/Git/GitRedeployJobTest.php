<?php

namespace Tests\Unit\Git;

use App\Exceptions\ProblemException;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use App\Models\TaskLog;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Git\CheckoutRedeploy;
use Mockery;
use Tests\Unit\DeployHook\DeployHookTestCase;

/**
 * A queued git pull, branch change or revert: the worker changes the
 * checkout and rebuilds, with the real GitActions, git layer and served-tree
 * settle on a fake host ({@see FakesGitHost}); only the rebuild itself is
 * recorded ({@see RecordingCheckoutRedeploy}).
 */
class GitRedeployJobTest extends DeployHookTestCase
{
    use FakesGitHost;

    private const HEAD = 'c0ffee0000000000000000000000000000000001';

    private RecordingCheckoutRedeploy $redeploy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGitHost();
        $this->withRepository();
        $this->respond("'rev-parse' 'HEAD'", 0, self::HEAD . "\n");
        $this->useRedeploy(new RecordingCheckoutRedeploy());
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        DeployLogger::deleteUserLogs('alice');
        $this->restoreHost();
        parent::tearDown();
    }

    private function useRedeploy(RecordingCheckoutRedeploy $redeploy): void
    {
        $this->redeploy = $redeploy;
        $this->app->instance(CheckoutRedeploy::class, $redeploy);
    }

    /** The previous version's container still runs: a settle puts its tree back. */
    private function previousStillRuns(): void
    {
        $this->respond('{{.State.Running}}', 0, "true\n");
    }

    /**
     * @param array<string, string> $git
     */
    private function runJob(string $action, array $git, ?\Closure $beforeRun = null): Task
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: 'alice', details: [
            'username' => 'alice',
            'action' => $action,
            ...$git,
        ]);
        $job = (new RebuildProject('alice', $action, null, null, null, $git))->attachTask($task);
        if ($beforeRun !== null) {
            $beforeRun($task);
        }
        try {
            $this->app->call([$job, 'handle']);
        } catch (\Throwable) {
            // runTask() has recorded it on the task, which is what is asserted.
        }

        return $task->refresh();
    }

    /** What POST /tasks/{id}/cancel does to the running task. */
    private function cancelTheRunningTask(): void
    {
        $killer = Mockery::mock(ProcessTreeKiller::class);
        $killer->shouldReceive('kill')->andReturnNull();
        $running = Task::query()->where('job_type', RebuildProject::class)->firstOrFail();
        $this->assertTrue((new TaskCanceller($killer))->cancel($running)['cancelled']);
    }

    private function assertDeployClosed(string $status): void
    {
        $latest = DeployLogger::readLatestFor('alice');
        $this->assertSame($status, $latest['status']);
        $this->assertNotNull($latest['finished_at'], 'finished: telemetry and a pending push follow-up ran');
        $this->assertFalse(DeployLogger::isLockedFor('alice'));
    }

    private function assertNothingAside(): void
    {
        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
    }

    public function test_a_pull_changes_the_checkout_then_rebuilds_and_completes_the_task(): void
    {
        $this->user('main');

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'force']);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status, json_encode($task->details));
        $this->assertTrue($this->called("'reset' '--hard' 'origin/main'"));
        // The deploy, and its lock, were open before the checkout was touched, and closed before the settle.
        $this->assertSame(['aside:locked', 'rebuild:git:' . self::HEAD, 'settle:checkout removed'], $this->redeploy->events);
        $this->assertSame(self::HEAD, $task->details['commit']);
        $this->assertSame('deploy', $task->details['checkout']['managed_by']);
        $this->assertSame('success', $task->details['deployment_status']);
        $latest = DeployLogger::readLatestFor('alice');
        $this->assertSame($latest['id'], $task->details['deploy_id']);
        $this->assertDeployClosed(DeployLogger::STATUS_SUCCESS);
        $logged = TaskLog::query()->where('task_id', $task->id)->pluck('log')->implode("\n");
        $this->assertStringContainsString('Pulling the connected branch (force) before the rebuild', $logged);
    }

    public function test_a_branch_change_and_a_revert_run_their_own_git_commands(): void
    {
        $user = $this->user('main');
        $this->respond("'fetch' 'origin' '+refs/heads/dev:refs/remotes/origin/dev'", 0);

        $changed = $this->runJob(ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']);

        $this->assertSame(Task::STATUS_COMPLETED, $changed->status, json_encode($changed->details));
        $this->assertTrue($this->called("'checkout' '-B' 'dev' 'origin/dev'"));
        $this->assertSame('dev', $user->refresh()->getGitBranch());

        $reverted = $this->runJob(ProjectRebuild::REVERT, ['path' => 'project', 'ref' => 'abc123']);

        $this->assertSame(Task::STATUS_COMPLETED, $reverted->status, json_encode($reverted->details));
        $this->assertTrue($this->called("'reset' '--hard' 'abc123'"));
        $this->assertCount(2, array_filter($this->redeploy->events, static fn (string $e): bool => str_starts_with($e, 'rebuild:')));
        $this->assertSame('dev', $user->refresh()->getGitBranch(), 'a revert leaves the branch records alone');
    }

    /** A `running` log a killed process left is not resumed: the change gets a deploy of its own, as a push does. */
    public function test_a_dead_deploy_is_not_resumed(): void
    {
        $this->user('main');
        $dead = DeployLogger::start('alice');
        $deadId = $dead->getDeployId();
        unset($dead);
        gc_collect_cycles();

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'ff']);

        $this->assertSame(Task::STATUS_COMPLETED, $task->status, json_encode($task->details));
        $this->assertNotSame($deadId, $task->details['deploy_id']);
        $this->assertStringNotContainsString('Pulling', implode("\n", array_column(DeployLogger::forDeploy('alice', $deadId)->entries(), 'msg')));
    }

    public function test_a_failed_rebuild_fails_the_task_with_its_problem(): void
    {
        $this->user('main');
        $this->previousStillRuns();
        $this->useRedeploy($this->failingRebuild());

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'ff']);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('A build step failed (exit code 3)', $task->details['error']);
        $this->assertSame('build-step-failed', $task->details['problems'][0]['code']);
        $this->assertSame('running', $task->details['problems'][0]['stage']);
        $this->assertArrayNotHasKey('commit', $task->details, 'nothing was deployed');
        $this->assertSame('settle:checkout restored', $this->redeploy->events[array_key_last($this->redeploy->events)], 'the served tree is back after the failure');
        $this->assertDeployClosed(DeployLogger::STATUS_FAILED);
    }

    private function failingRebuild(): RecordingCheckoutRedeploy
    {
        return new RecordingCheckoutRedeploy(static function (?DeployLogger $logger): never {
            // What rebuildFromCheckout() does with a build step that fails.
            $logger?->finish(DeployLogger::STATUS_FAILED, 'A build step failed (exit code 3)');
            throw ProblemException::deploy('build-step-failed', 'A build step failed (exit code 3)', 'running');
        });
    }

    /**
     * The branch change was recorded, then the rebuild failed and the old
     * checkout, still on main, was put back: the records name main again,
     * or the next pull would fetch dev into it.
     */
    public function test_a_failed_branch_change_whose_old_checkout_was_put_back_records_the_old_branch(): void
    {
        $user = $this->user('main');
        $this->previousStillRuns();
        $this->useRedeploy($this->failingRebuild());

        $task = $this->runJob(ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertTrue($this->called("'checkout' '-B' 'dev' 'origin/dev'"));
        $user->refresh();
        $this->assertSame('main', $user->getGitBranch());
        $this->assertSame('main', $user->getSiteGit('project')['branch']);
        $this->assertArrayNotHasKey('project', $user->getDetails()['site_git'] ?? [], 'as it was: read from git_repo');
    }

    /** The new checkout stayed (the previous version was gone): its records stay too. */
    public function test_a_failed_branch_change_that_kept_the_new_checkout_keeps_its_records(): void
    {
        $user = $this->user('main');
        $this->useRedeploy($this->failingRebuild());

        $task = $this->runJob(ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('settle:checkout removed', $this->redeploy->events[array_key_last($this->redeploy->events)]);
        $this->assertSame('dev', $user->refresh()->getGitBranch());
        $this->assertSame('dev', $user->getSiteGit('project')['branch']);
    }

    /** Git refused the change in the worker: nothing is rebuilt, and the deploy it opened is closed before the settle. */
    public function test_a_change_git_refuses_fails_the_task_and_closes_its_deploy(): void
    {
        $this->user('main');
        $this->previousStillRuns();
        $this->respond("'status' '--porcelain'", 0, " M index.html\n");

        $task = $this->runJob(ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']);

        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('Working tree is dirty.', $task->details['error']);
        $this->assertSame('git', $task->details['problems'][0]['field']);
        $this->assertSame(['aside:locked', 'settle:checkout restored'], $this->redeploy->events);
        $this->assertFalse($this->called("'checkout' '-B'"));
        $this->assertDeployClosed(DeployLogger::STATUS_FAILED);
        $this->assertSame('Could not change the checkout: Working tree is dirty.', DeployLogger::readLatestFor('alice')['error']);
    }

    public function test_a_task_cancelled_while_queued_never_touches_the_checkout(): void
    {
        $this->user('main');

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project'], static function (Task $task): void {
            $task->markCancelled();
        });

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame([], $this->calls());
        $this->assertSame([], $this->redeploy->events);
        $this->assertNull(DeployLogger::readLatestFor('alice'), 'no deploy was opened');
    }

    /**
     * task_cancel once the tree is aside and before git runs: every git
     * command then throws. The deploy is finished at once, so the settle
     * gets the lock and puts the served tree back in the same job.
     */
    public function test_a_cancel_before_the_git_step_closes_the_deploy_and_puts_the_served_tree_back(): void
    {
        $this->user('main');
        $this->previousStillRuns();
        $this->redeploy->beforeChange = fn () => $this->cancelTheRunningTask();

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'ff']);

        $this->assertSame(['aside:locked', 'settle:checkout restored'], $this->redeploy->events);
        $this->assertNothingAside();
        $this->assertDeployClosed(DeployLogger::STATUS_CANCELLED);
        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame('deploy_cancelled', $task->details['problems'][0]['code'], json_encode($task->details));
    }

    /** The cancel lands while `git fetch` runs, as requestCancel() writes it: the same, from inside a command. */
    public function test_a_cancel_while_git_fetches_closes_the_deploy_and_puts_the_served_tree_back(): void
    {
        $this->user('main');
        $this->previousStillRuns();
        $this->cancelDuring("'fetch'");

        $task = $this->runJob(ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'ff']);

        $this->assertTrue($this->called("'fetch'"));
        $this->assertSame(['aside:locked', 'settle:checkout restored'], $this->redeploy->events);
        $this->assertNothingAside();
        $this->assertDeployClosed(DeployLogger::STATUS_CANCELLED);
        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame('deploy_cancelled', $task->details['problems'][0]['code'], json_encode($task->details));
        $this->assertSame('Deployment cancelled by user', $task->details['problems'][0]['message']);
    }

    /** task_cancel while the rebuild runs: the deploy is cancelled, and so is the task. */
    public function test_cancelling_the_running_task_cancels_its_deploy(): void
    {
        $this->user('main');
        $this->useRedeploy($this->cancelledRebuild());

        $task = $this->runJob(ProjectRebuild::REVERT, ['path' => 'project', 'ref' => 'HEAD']);

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertSame('deploy_cancelled', $task->details['problems'][0]['code']);
        $this->assertDeployClosed(DeployLogger::STATUS_CANCELLED);
    }

    /**
     * A branch change cancelled during its rebuild, with the previous version
     * still serving: its checkout and the records naming main come back, and
     * git runs again on the account at once.
     */
    public function test_a_cancelled_branch_change_puts_the_old_checkout_and_branch_back(): void
    {
        $user = $this->user('main');
        $this->previousStillRuns();
        $this->useRedeploy($this->cancelledRebuild());

        $task = $this->runJob(ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']);

        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertTrue($this->called("'checkout' '-B' 'dev' 'origin/dev'"));
        $this->assertSame('settle:checkout restored', $this->redeploy->events[array_key_last($this->redeploy->events)]);
        $user->refresh();
        $this->assertSame('main', $user->getGitBranch());
        $this->assertArrayNotHasKey('project', $user->getDetails()['site_git'] ?? []);
        $this->assertDeployClosed(DeployLogger::STATUS_CANCELLED);
        $this->assertSame(self::HEAD, $user->project()->git('project')->readHeadCommit(), 'a finished cancelled deploy no longer stops git');
    }

    private function cancelledRebuild(): RecordingCheckoutRedeploy
    {
        return new RecordingCheckoutRedeploy(function (?DeployLogger $logger): never {
            $logger->stage(DeployLogger::STAGE_RUNNING);
            $this->cancelTheRunningTask();
            // What rebuildFromCheckout() does with the DeployCancelledException its next step throws.
            $logger->finish(DeployLogger::STATUS_CANCELLED, 'Deployment cancelled by user');
            throw ProblemException::deploy('deploy_cancelled', 'Deployment cancelled by user', 'running');
        });
    }

    /** Commands matching `$needle` flip latest.json to cancelled while they run, as requestCancel() does. */
    private function cancelDuring(string $needle): void
    {
        $latest = var_export((new DeployLogPaths('alice'))->latest(), true);
        $flip = 'php -r ' . escapeshellarg('$f=' . $latest . ';$j=json_decode(file_get_contents($f),true);$j["status"]="cancelled";file_put_contents($f,json_encode($j));');
        foreach (['sudo', 'docker', 'nsenter'] as $bin) {
            rename("{$this->shim}/{$bin}", "{$this->shim}/{$bin}.real");
            file_put_contents("{$this->shim}/{$bin}", "#!/bin/sh\ncase \"\$*\" in *\"{$needle}\"*) {$flip};; esac\nexec \"\$(dirname \"\$0\")/{$bin}.real\" \"\$@\"\n");
            chmod("{$this->shim}/{$bin}", 0755);
        }
    }
}
