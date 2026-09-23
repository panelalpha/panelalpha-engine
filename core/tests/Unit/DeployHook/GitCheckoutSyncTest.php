<?php

namespace Tests\Unit\DeployHook;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Tests\TestCase;
use Tests\Unit\System\Project\FakeGitRunner;
use Tests\Unit\System\Project\TestableProjectGit;

/**
 * The real pull-then-rebuild, with git and Docker faked at their edges: that a
 * push makes the checkout match the repository (a forced pull, not a
 * fast-forward) and that the rebuild that follows is told it came from a push
 * and which commit it is running.
 */
class GitCheckoutSyncTest extends TestCase
{
    private const HEAD = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->username = 'sync-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    private function user(): ModelsUser
    {
        $user = new ModelsUser();
        $user->username = $this->username;
        $user->setDetails([
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
            'template' => 'dind',
        ]);

        return $user;
    }

    private function runner(): FakeGitRunner
    {
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'rev-parse HEAD' => self::HEAD . "\n",
        ];

        return $runner;
    }

    /**
     * @return list<string> the git subcommands run, joined, without the -c/-C prefix
     */
    private function subcommands(FakeGitRunner $runner): array
    {
        return array_map(
            static fn (array $argv): string => implode(' ', array_slice($argv, 5)),
            $runner->commands,
        );
    }

    public function test_it_force_pulls_then_rebuilds_from_the_checkout_naming_the_push_and_the_commit(): void
    {
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner = $this->runner());
        $redeploy = new SpyCheckoutRedeploy();

        (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild($this->user(), 'project', 'pushed-sha');

        $commands = $this->subcommands($runner);
        $this->assertContains('fetch origin', $commands);
        $this->assertContains('reset --hard origin/main', $commands, 'a push makes the checkout match the repository');
        $this->assertContains('clean -fd', $commands);
        $this->assertNotContains('merge --ff-only origin/main', $commands);
        $this->assertLessThan(
            array_search('reset --hard origin/main', $commands, true),
            array_search('fetch origin', $commands, true),
        );

        $this->assertCount(1, $redeploy->calls);
        $this->assertSame('push', $redeploy->calls[0]['source']);
        // The commit the checkout is now at, not the one the push claimed.
        $this->assertSame(self::HEAD, $redeploy->calls[0]['commit']);
        // The rebuild runs under the deploy started before the pull, rather
        // than opening a second one after it.
        $this->assertInstanceOf(DeployLogger::class, $redeploy->calls[0]['logger']);
        $this->assertFalse(DeployLogger::isLockedFor($this->username), 'the deploy is finished and its lock released');
        $this->assertNotSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($this->username)['status'] ?? null);
    }

    /**
     * A deploy killed before finish() leaves latest.json `running`. The push
     * after it used to resume that dead deploy: its log continued the dead
     * one's under the dead one's id, and the delivery got no deploy_id.
     */
    public function test_a_push_after_a_killed_deploy_starts_a_new_deploy_rather_than_resuming_the_dead_one(): void
    {
        $dead = DeployLogger::start($this->username);
        $deadId = $dead->getDeployId();
        unset($dead); // the process is gone, its lock with it; finish() never ran
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($this->username)['status'] ?? null);

        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $this->runner());
        $redeploy = new SpyCheckoutRedeploy();
        $started = [];

        (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild(
            $this->user(),
            'project',
            null,
            static function (string $deployId) use (&$started): void {
                $started[] = $deployId;
            },
        );

        $this->assertCount(1, $started, 'the delivery is told which deploy it runs under, once');
        $this->assertNotSame($deadId, $started[0]);
        $this->assertSame($started[0], $redeploy->calls[0]['logger']->getDeployId());
    }

    public function test_it_does_not_touch_the_checkout_while_another_deploy_holds_the_lock(): void
    {
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner = $this->runner());
        $redeploy = new SpyCheckoutRedeploy();
        $running = DeployLogger::start($this->username);

        try {
            (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild($this->user(), 'project', null);
            $this->fail('A push rewrote the checkout under a running deploy.');
        } catch (DeployAlreadyRunningException) {
            $this->assertNotContains('reset --hard origin/main', $this->subcommands($runner));
            $this->assertNotContains('clean -fd', $this->subcommands($runner));
            $this->assertSame([], $redeploy->calls);
        } finally {
            $running->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    public function test_a_failed_pull_finishes_the_deploy_it_started(): void
    {
        $runner = $this->runner();
        $runner->failIfContains = ['fetch origin'];
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner);

        try {
            (new TestableGitCheckoutSync(new SpyCheckoutRedeploy(), $git))->pullAndRebuild($this->user(), 'project', null);
            $this->fail('A failed fetch was swallowed.');
        } catch (GitException) {
            $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::readLatestFor($this->username)['status'] ?? null);
            $this->assertFalse(DeployLogger::isLockedFor($this->username));
        }
    }

    public function test_it_falls_back_to_the_pushed_commit_when_the_checkout_cannot_say(): void
    {
        $runner = $this->runner();
        $runner->stdout['rev-parse HEAD'] = '';
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner);
        $redeploy = new SpyCheckoutRedeploy();

        (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild($this->user(), 'project', 'pushed-sha');

        $this->assertSame('pushed-sha', $redeploy->calls[0]['commit']);
    }

    public function test_a_failed_pull_does_not_rebuild(): void
    {
        $runner = $this->runner();
        $runner->failIfContains = ['fetch origin'];
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner);
        $redeploy = new SpyCheckoutRedeploy();

        try {
            (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild($this->user(), 'project', null);
            $this->fail('A failed fetch was swallowed.');
        } catch (GitException) {
            $this->assertSame([], $redeploy->calls);
        }
    }

    public function test_a_rebuild_that_fails_leaves_the_pulled_commit_in_place(): void
    {
        $runner = $this->runner();
        $git = new TestableProjectGit(new Project(new System(), $this->user()), 'project', $runner);
        $redeploy = new SpyCheckoutRedeploy(new \RuntimeException('build failed'));

        try {
            (new TestableGitCheckoutSync($redeploy, $git))->pullAndRebuild($this->user(), 'project', null);
            $this->fail('A failed rebuild was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('build failed', $e->getMessage());
        }

        // Handled like a manual pull: no reset back to the previous commit.
        $this->assertNotContains(
            'reset --hard refs/panelalpha/backup',
            $this->subcommands($runner),
        );
    }
}
