<?php

namespace Tests\Unit\Git;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use Tests\Unit\DeployHook\DeployHookTestCase;

/**
 * A branch change records the new branch before its rebuild. When the worker
 * running it dies (kill -9, OOM, a timeout, a core restart), no catch runs:
 * the sweep puts the previous checkout back, and the records that named its
 * branch must come back with it. The real CheckoutAside and GenerationSweep
 * on a fake host ({@see FakesGitHost}).
 */
class BranchChangeSweepTest extends DeployHookTestCase
{
    use FakesGitHost;

    private const GONE = ['pid' => 999999, 'start' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGitHost();
        $this->withRepository();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs('alice');
        $this->restoreHost();
        parent::tearDown();
    }

    /** As the worker had got it when it died: the tree aside, the checkout and the records on dev, the rebuild running. */
    private function diedDuringTheRebuild(User $user): void
    {
        $logger = DeployLogger::start('alice');
        (new CheckoutAside($this->runtime($user)))->moveAside(['app-1'], copyBack: true);
        $user->project()->git('project')->changeBranch('dev');
        $this->assertSame('dev', $user->refresh()->getGitBranch(), 'recorded before the rebuild');

        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, ['owner' => self::GONE + ['at' => time()]] + $state->get(GenerationState::CHECKOUT));
        // The kernel drops a dead process's lock.
        unset($logger);
        gc_collect_cycles();
    }

    private function runtime(User $user): Dind
    {
        $runtime = $user->project()->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** What the sweep, in its own process, reads. */
    private function sweep(): ?array
    {
        return GenerationSweep::settleInterrupted($this->runtime(User::query()->where('username', 'alice')->firstOrFail()));
    }

    public function test_the_sweep_puts_the_previous_checkout_back_and_its_branch_with_it(): void
    {
        $user = $this->user('main');
        $this->respond('{{.State.Running}}', 0, "true\n");
        $this->diedDuringTheRebuild($user);

        $this->assertSame(['checkout restored'], $this->sweep());

        $aside = (new CheckoutAside($this->runtime($user)))->asidePath();
        $this->assertTrue($this->called("mv -T {$aside} "), implode("\n", $this->calls()));
        $user->refresh();
        $this->assertSame('main', $user->getGitBranch());
        $this->assertSame('main', $user->getSiteGit('project')['branch']);
        $this->assertArrayNotHasKey('project', $user->getDetails()['site_git'] ?? [], 'as it was: read from git_repo');
        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
        $latest = DeployLogger::readLatestFor('alice');
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $latest['error']);
    }

    /** The checkout had its own entry: only its branch goes back, the remote and token stay. */
    public function test_an_entry_of_its_own_gets_its_branch_back(): void
    {
        $user = $this->user('main', ['site_git' => ['project' => [
            'repo_url' => 'https://github.com/octocat/Hello-World.git', 'branch' => 'main', 'token' => 'ghp_kept',
        ]]]);
        $this->respond('{{.State.Running}}', 0, "true\n");
        $this->diedDuringTheRebuild($user);

        $this->sweep();

        $user->refresh();
        $this->assertSame('main', $user->getGitBranch());
        $this->assertSame(['repo_url' => 'https://github.com/octocat/Hello-World.git', 'branch' => 'main', 'token' => 'ghp_kept'], $user->getSiteGit('project'));
    }

    /** The previous version was already replaced: the new checkout stays, and so do its records. */
    public function test_a_new_version_that_replaced_the_previous_one_keeps_the_new_branch(): void
    {
        $user = $this->user('main');
        $this->respond('{{.State.Running}}', 0, "gone\n");
        $this->diedDuringTheRebuild($user);

        $this->assertSame(['checkout removed'], $this->sweep());

        $user->refresh();
        $this->assertSame('dev', $user->getGitBranch());
        $this->assertSame('dev', $user->getSiteGit('project')['branch']);
    }

    /** The previous version is started again from the tree aside ({@see \App\System\Project\Dind\Generation\PreviousVersion}). */
    public function test_bringing_the_previous_checkout_back_brings_its_branch_back(): void
    {
        $user = $this->user('main');
        $this->diedDuringTheRebuild($user);
        $fresh = User::query()->where('username', 'alice')->firstOrFail();

        $this->assertTrue((new CheckoutAside($this->runtime($fresh)))->bringBack());

        $this->assertSame('main', $user->refresh()->getGitBranch());
        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
    }

    /** A redeploy that changed no branch writes nothing back: not even a stale copy of the rest. */
    public function test_a_tree_put_back_after_a_pull_leaves_the_records_alone(): void
    {
        $user = $this->user('main');
        $this->respond('{{.State.Running}}', 0, "true\n");
        $runtime = $this->runtime($user);
        (new CheckoutAside($runtime))->moveAside(['app-1'], copyBack: true);
        $elsewhere = User::query()->where('username', 'alice')->firstOrFail();
        $elsewhere->setDetails(['deployment_status' => 'partial']);
        $elsewhere->save();

        $this->assertSame('restored', (new CheckoutAside($runtime))->settle(false));

        $this->assertSame('partial', $user->refresh()->getDetails()['deployment_status'] ?? null);
        $this->assertSame('main', $user->getGitBranch());
    }
}
