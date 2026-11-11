<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\Git\FakesGitHost;

/**
 * The sweep settling a checkout moved aside: the tree aside is never deleted
 * while ~/project is missing, and a dockerd that does not answer leaves
 * everything for a later sweep.
 */
class CheckoutAsideSettleTest extends DeployHookTestCase
{
    use FakesGitHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGitHost();
        @mkdir(DeployLogPaths::userDir('alice'), 0755, true);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs('alice');
        $this->restoreHost();
        parent::tearDown();
    }

    /** Moved aside by a process that died, the app stopped, nothing in ~/project: the sweep puts the tree back. */
    public function test_the_only_copy_of_the_checkout_is_put_back_not_removed(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: false));
        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, ['owner' => ['pid' => 999999, 'start' => null, 'at' => time()]] + $state->get(GenerationState::CHECKOUT));
        $this->respondToContainers(['app-1'], 0, '[null]');
        $before = count($this->calls());

        $this->assertSame(['alice: checkout restored'], GenerationSweep::run());

        $after = array_slice($this->calls(), $before);
        $this->assertSame([self::containersAsked(['app-1'])], self::containerQuestions($after));
        $this->assertContains('mv -T -- ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath(), $after);
        $this->assertNotContains('rm -rf -- ' . $aside->asidePath(), $after);
    }

    /** An aside directory on an account with no state file at all: the sweep still finds it and puts it back. */
    public function test_the_sweep_puts_back_an_aside_no_state_file_names(): void
    {
        $runtime = $this->runtime();
        $aside = (new CheckoutAside($runtime))->asidePath();
        $this->respond('-name .project-prev', 0, $runtime->homeDirPath() . "\n");
        $this->respond('test -d ' . $aside, 0);
        $this->respond('test -d ' . $runtime->userAppDirPath(), 1);

        $this->assertSame(['alice: checkout restored'], GenerationSweep::run());
        $this->assertTrue($this->called('mv -T -- ' . $aside . ' ' . $runtime->userAppDirPath()));
        $this->assertSame([], self::containerQuestions($this->calls()));
    }

    /** Beside a checkout that holds files the unrecorded tree stays where it is, and the sweep says nothing. */
    public function test_the_sweep_leaves_an_unrecorded_aside_beside_a_full_checkout(): void
    {
        $runtime = $this->runtime();
        $aside = (new CheckoutAside($runtime))->asidePath();
        $this->respond('-name .project-prev', 0, $runtime->homeDirPath() . "\n");
        $this->respond('test -d ' . $aside, 0);
        $this->respond('ls -1A -- ' . $runtime->userAppDirPath(), 0, "index.php\n");

        $this->assertSame([], GenerationSweep::run());
        $this->assertFalse($this->called('mv -T -- ' . $aside));
        $this->assertFalse($this->called('rm -rf -- ' . $aside));
        $this->assertSame([], self::containerQuestions($this->calls()));
    }

    /** A succeeded deploy that left no ~/project does not delete the tree aside either. */
    public function test_a_succeeded_deploy_without_a_checkout_keeps_the_tree_aside(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: false));

        $this->assertSame('restored', $aside->settle(true));
        $this->assertTrue($this->called('mv -T -- ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
        $this->assertSame([], self::containerQuestions($this->calls()));
    }

    /**
     * A copy back that stopped partway leaves ~/project holding only a partial
     * tree: the sweep puts the recorded tree back and never deletes it.
     */
    public function test_a_half_copied_checkout_is_restored_not_removed(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, [
            'path' => $aside->asidePath(),
            'containers' => ['app-1'],
            'binds' => true,
            'copy_back_pending' => true,
            'owner' => ['pid' => 999999, 'start' => null, 'at' => time()],
        ]);
        $this->respond('test -d ' . $aside->asidePath(), 0, '');
        $this->respondToContainers(['app-1'], 0, '[{"State":{"Running":false}}]');

        $this->assertSame('restored', $aside->settle(false));

        $this->assertSame([self::containersAsked(['app-1'])], self::containerQuestions($this->calls()));

        $this->assertTrue($this->called('mv -T -- ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
        $this->assertFalse($this->called('rm -rf -- ' . $aside->asidePath()));
    }

    /** A dockerd that cannot be asked is not "every container stopped": nothing is touched. */
    public function test_a_docker_that_does_not_answer_leaves_the_checkout_for_a_later_sweep(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: true));
        $this->respondToContainers(['app-1'], 69, "the Docker daemon of container alice is not running\n");
        $before = count($this->calls());

        $this->assertNull($aside->settle());

        $this->assertSame([self::containersAsked(['app-1'])], self::containerQuestions(array_slice($this->calls(), $before)));
        $touched = array_filter(
            array_slice($this->calls(), $before),
            static fn (string $call): bool => str_starts_with($call, 'rm ') || str_starts_with($call, 'mv '),
        );
        $this->assertSame([], array_values($touched));
        $this->assertTrue($aside->restorable());
    }

    /** A daemon not up yet fails the question rather than answering "gone". */
    public function test_the_running_check_is_unknown_while_dockerd_is_not_up(): void
    {
        $this->respondToContainers(['app-1'], 69, "the Docker daemon of container alice is not running\n");
        $this->assertNull(CheckoutAside::allRunning($this->runtime(), ['app-1']));
        $this->assertSame([self::containersAsked(['app-1'])], self::containerQuestions($this->calls()));
    }

    /**
     * Answers only the question about $ids, so a question about other containers gets no answer.
     *
     * @param  list<string>  $ids
     */
    private function respondToContainers(array $ids, int $code, string $output): void
    {
        $this->respond(self::containersAsked($ids), $code, $output);
    }

    /** @param  list<string>  $ids */
    private static function containersAsked(array $ids): string
    {
        return 'pa-root account-docker.containers -- alice ' . implode(' ', $ids);
    }

    /**
     * Every question about containers among $calls, as asked.
     *
     * @param  list<string>  $calls
     * @return list<string>
     */
    private static function containerQuestions(array $calls): array
    {
        return array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, 'account-docker.containers')));
    }

    private function runtime(): Dind
    {
        $runtime = $this->user()->project()->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
